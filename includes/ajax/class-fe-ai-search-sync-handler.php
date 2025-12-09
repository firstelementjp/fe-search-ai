<?php
/**
 * Handles all content synchronization and data retrieval tasks.
 *
 * This file defines the fe_ai_search_Sync_Handler class, which is responsible for
 * all processes related to indexing content, creating vector embeddings, and
 * retrieving relevant information from the database for the chat handler.
 *
 * @package    fe-ai-search
 * @subpackage Ajax
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FEAISearch\Core\FE_AI_Search_License;
use U7aro\TinySegmenter\TinySegmenter;
use WP_Error;

/**
 * Main controller for all data synchronization processes.
 *
 * This class manages both manual (AJAX-based) and real-time (hook-based)
 * content synchronization. It also provides the core search method (`find_similar_chunks`)
 * used by other classes to retrieve contextually relevant data.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Ajax
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_Sync_Handler {

	private $options = [];
	private $is_license_active;
	private $segmenter;
	private $japanese_tokenizer = 'tinysegmenter';

	/**
	 * Initializes options, tokenizer selection, license status, and hooks.
	 *
	 * @return void
	 */
	public function __construct() {

		$this->options           = get_option( 'fe_ai_search_settings', [] );
		$this->segmenter         = new TinySegmenter();
		$this->is_license_active = FE_AI_Search_License::is_pro_active();

		add_filter( 'fe_ai_search_tokenizer_status', [ $this, 'add_japanese_tokenizer_status' ] );
		add_action( 'wp_ajax_fe_ai_search_start_sync', [ $this, 'ajax_start_sync' ] );
		add_action( 'wp_ajax_fe_ai_search_process_batch', [ $this, 'ajax_process_batch' ] );
		add_action( 'wp_ajax_fe_ai_search_update_sync_timestamp', [ $this, 'ajax_update_sync_timestamp' ] );
		add_action( 'wp_ajax_fe_ai_search_delete_vectors', [ $this, 'ajax_delete_vectors' ] );
		add_action( 'wp_ajax_fe_ai_search_start_smart_sync', [ $this, 'ajax_start_smart_sync' ] );
		add_action( 'wp_ajax_fe_ai_search_update_settings_hash', [ $this, 'ajax_update_settings_hash' ] );

		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'render_i18n_notice' ] );
		}
	}

	/**
	 * Starts a full rebuild of the index.
	 *
	 * Truncates vector and keyword tables and returns target post IDs
	 * and pagination data to the client.
	 *
	 * @return void
	 */
	public function ajax_start_sync() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_ai_search_vectors';
		$index_table   = $wpdb->prefix . 'fe_ai_search_keyword_index';
		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		$wpdb->query( "TRUNCATE TABLE `{$index_table}`" );

		// Normalize sync options structure to a safe array using new schema.
		$sync_root = $this->options['sync'] ?? [];
		if ( ! is_array( $sync_root ) ) {
			$sync_root = [];
		}
		$targets = isset( $sync_root['targets'] ) && is_array( $sync_root['targets'] ) ? $sync_root['targets'] : [];

		$include_ids_str = '';
		$exclude_ids_str = '';
		if ( isset( $sync_root['include'] ) && is_array( $sync_root['include'] ) ) {
			$include_ids_str = (string) ( $sync_root['include']['ids'] ?? '' );
		}
		if ( isset( $sync_root['exclude'] ) && is_array( $sync_root['exclude'] ) ) {
			$exclude_ids_str = (string) ( $sync_root['exclude']['ids'] ?? '' );
		}

		$limit = isset( $sync_root['limit'] ) ? (int) $sync_root['limit'] : -1;

		$post_types_to_sync = [];
		if ( ! empty( $targets ) ) {
			foreach ( $targets as $pt_slug => $pt_options ) {
				if ( ! empty( $pt_options['enabled'] ) ) {
					// Skip if no Include in Chunk Data options are selected
					$has_any_snippet = (
						! empty( $pt_options['snippet_include_title'] ) ||
						! empty( $pt_options['snippet_include_content'] ) ||
						! empty( $pt_options['snippet_include_date'] ) ||
						! empty( $pt_options['snippet_include_author'] ) ||
						! empty( $pt_options['snippet_taxonomies'] ) ||
						( ! empty( $pt_options['enable_custom_fields'] ) && ! empty( $pt_options['snippet_custom_fields'] ) )
					);
					if ( $has_any_snippet ) {
						$post_types_to_sync[] = $pt_slug;
					}
				}
			}
		}

		$args = [
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'cache_results'  => false,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$include_ids = array_filter( array_map( 'intval', explode( ',', $include_ids_str ) ) );
		$exclude_ids = array_filter( array_map( 'intval', explode( ',', $exclude_ids_str ) ) );

		if ( ! empty( $include_ids ) ) {
			$args['post__in'] = $include_ids;
		} else {
			$args['post_type'] = empty( $post_types_to_sync ) ? [ 'post', 'page' ] : $post_types_to_sync;
			if ( ! empty( $exclude_ids ) ) {
				$args['post__not_in'] = $exclude_ids;
			}
		}

		/**
		 * Filters the query arguments used to fetch posts for synchronization.
		 *
		 * This hook allows developers to add custom query parameters (e.g., meta_query,
		 * category__in) to modify which posts are selected for indexing by the AI.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args The array of query arguments passed to `get_posts()`.
		 */
		$args = apply_filters( 'fe_ai_search_sync_query_args', $args );

		$all_post_ids = get_posts( $args );
		$total_posts  = count( $all_post_ids );

		$sync_options = isset( $this->options['sync'] ) && is_array( $this->options['sync'] ) ? $this->options['sync'] : [];
		$batch_size   = (int) ( $sync_options['batch_size'] ?? 10 );
		$total_pages  = ceil( $total_posts / $batch_size );

		wp_send_json_success(
			[
				'total_pages' => $total_pages,
				'total_posts' => $total_posts,
				'post_ids'    => $all_post_ids, // Pass the list of IDs to be processed to JS
				'batch_size'  => $batch_size,
			]
		);
	}

	/**
	 * Starts a smart (incremental) sync based on the last sync timestamp.
	 *
	 * Detects deleted and updated posts and returns only the IDs that
	 * need to be reindexed.
	 *
	 * @return void
	 */
	public function ajax_start_smart_sync() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		// Use the new sync schema for static configuration: enabled post types,
		// limits, etc. are stored under fe_ai_search_settings['sync'].
		$sync_root = $this->options['sync'] ?? [];
		if ( ! is_array( $sync_root ) ) {
			$sync_root = [];
		}
		$targets    = isset( $sync_root['targets'] ) && is_array( $sync_root['targets'] ) ? $sync_root['targets'] : [];
		$sync_limit = isset( $sync_root['limit'] ) ? (int) $sync_root['limit'] : -1;

		// Runtime state (status/settings) is stored separately in
		// fe_ai_search_sync_state to avoid interference from settings sanitization
		// or other code paths that update fe_ai_search_settings.
		$sync_state         = get_option( 'fe_ai_search_sync_state', [] );
		$sync_status        = isset( $sync_state['status'] ) && is_array( $sync_state['status'] ) ? $sync_state['status'] : [];
		$state_settings     = isset( $sync_state['settings'] ) && is_array( $sync_state['settings'] ) ? $sync_state['settings'] : [];
		$post_types_to_sync = [];
		if ( ! empty( $targets ) ) {
			foreach ( $targets as $pt_slug => $pt_options ) {
				if ( ! empty( $pt_options['enabled'] ) ) {
					$post_types_to_sync[] = $pt_slug;
				}
			}
		}

		if ( empty( $post_types_to_sync ) ) {
			wp_send_json_success(
				[
					'total_pages' => 0,
					'total_posts' => 0,
					'post_ids'    => [],
				]
			);
			return;
		}

		// Check if sync settings have changed.
		// Derive a pure "settings snapshot" from the static sync config by
		// removing any runtime fields before hashing.
		$settings_root = $sync_root;
		unset( $settings_root['status'], $settings_root['settings'] );
		$current_settings_hash = md5( serialize( $settings_root ) );
		$last_settings_hash    = $state_settings['hash'] ?? '';
		$last_sync_timestamp   = (int) ( $state_settings['last_sync_timestamp'] ?? 0 );

		if ( $last_sync_timestamp && $current_settings_hash !== $last_settings_hash ) {
			wp_send_json_error( [ 'message' => __( 'Sync settings have changed. Please use "Rebuild All" to apply the new settings.', 'fe-ai-search' ) ] );
		}

		// Find deleted posts
		global $wpdb;
		$vectors_table         = $wpdb->prefix . 'fe_ai_search_vectors';
		$indexed_post_ids      = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT post_id FROM {$vectors_table}" ) );
		$all_existing_post_ids = array_map(
			'intval',
			get_posts(
				[
					'post_type'      => $post_types_to_sync,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post_status'    => 'publish',
				]
			)
		);
		$deleted_post_ids      = array_diff( $indexed_post_ids, $all_existing_post_ids );

		if ( ! empty( $deleted_post_ids ) ) {
			foreach ( $deleted_post_ids as $post_id ) {
				$GLOBALS['fe_ai_search_sync_hooks']->delete_post_from_index( $post_id );
			}
		}

		// --- 4. Find new and updated posts ---
		// Baseline is now stored only in sync['status']['last_sync_timestamp'].
		$last_sync = isset( $sync_status['last_sync_timestamp'] ) ? (int) $sync_status['last_sync_timestamp'] : 0;

		// If there is no previous sync timestamp, we treat this as "no baseline"
		// and do NOT attempt a Smart Sync (to avoid syncing the entire site by mistake).
		if ( 0 === $last_sync ) {
			wp_send_json_success(
				[
					'total_pages' => 0,
					'total_posts' => 0,
					'post_ids'    => [],
					'message'     => __( 'No previous sync baseline found. Please run "Rebuild Index" once before using "Sync Changes".', 'fe-ai-search' ),
				]
			);
		}
		// Convert the stored local timestamp to a GMT datetime string for post_modified_gmt.
		$last_sync_local_datetime = date( 'Y-m-d H:i:s', $last_sync );
		$last_sync_gmt_timestamp  = get_gmt_from_date( $last_sync_local_datetime, 'U' );
		$last_sync_gmt_datetime   = date( 'Y-m-d H:i:s', $last_sync_gmt_timestamp );

		$updated_post_ids = get_posts(
			[
				'post_type'      => $post_types_to_sync,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => [
					[
						'column' => 'post_modified_gmt',
						'after'  => $last_sync_gmt_datetime,
					],
				],
			]
		);

		// --- 5. Apply global sync limit and return the list of IDs to process to JavaScript ---
		$post_ids_to_process = array_values( array_unique( $updated_post_ids ) );
		if ( $sync_limit > 0 && count( $post_ids_to_process ) > $sync_limit ) {
			$post_ids_to_process = array_slice( $post_ids_to_process, 0, $sync_limit );
		}
		$total_posts = count( $post_ids_to_process );

		// Enforce a minimum batch size of 1 to avoid division by zero.
		$raw_batch_size = isset( $sync_options['batch_size'] ) ? (int) $sync_options['batch_size'] : 10;
		$batch_size     = max( 1, $raw_batch_size );
		$total_pages    = ( $total_posts > 0 ) ? ceil( $total_posts / $batch_size ) : 0;

		// After a successful sync, the settings hash should be updated.
		// We do this here, assuming the JS process will complete.
		// update_option( 'fe_ai_search_last_settings_hash', $current_settings_hash );

		wp_send_json_success(
			[
				'total_pages' => $total_pages,
				'total_posts' => $total_posts,
				'post_ids'    => $post_ids_to_process,
				'batch_size'  => $batch_size,
			]
		);
	}

	/**
	 * Updates the saved hash of the sync settings.
	 * This is called after a successful sync process.
	 */
	public function ajax_update_settings_hash() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		// Pull the freshest static sync configuration from the main settings.
		$options   = get_option( 'fe_ai_search_settings', [] );
		$sync_root = isset( $options['sync'] ) && is_array( $options['sync'] ) ? $options['sync'] : [];

		// Derive the same settings snapshot used in ajax_start_smart_sync by
		// removing runtime/state fields before hashing.
		$settings_root = $sync_root;
		unset( $settings_root['status'], $settings_root['settings'] );
		$current_settings_hash = md5( serialize( $settings_root ) );

		// Runtime state (status/settings) is stored in a dedicated option so that
		// it is not affected by settings sanitization or other writers.
		$state = get_option( 'fe_ai_search_sync_state', [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		if ( ! isset( $state['settings'] ) || ! is_array( $state['settings'] ) ) {
			$state['settings'] = [];
		}
		if ( ! isset( $state['status'] ) || ! is_array( $state['status'] ) ) {
			$state['status'] = [];
		}

		$now = current_time( 'timestamp' );
		if ( empty( $state['status']['last_sync_timestamp'] ) ) {
			$state['status']['last_sync_timestamp'] = $now;
		}

		$state['settings']['hash']                = $current_settings_hash;
		$state['settings']['last_sync_timestamp'] = (int) $state['status']['last_sync_timestamp'];

		update_option( 'fe_ai_search_sync_state', $state );

		wp_send_json_success();
	}

	/**
	 * Processes a batch of posts for indexing.
	 *
	 * Creates text chunks, requests embeddings, stores vectors, and
	 * builds the keyword index for the given post IDs.
	 *
	 * @return void
	 */
	public function ajax_process_batch() {
		try {
			set_time_limit( 0 );
			ob_start();

			check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

			// Process input data
			$page          = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
			$post_ids_json = isset( $_POST['post_ids'] ) ? stripslashes( $_POST['post_ids'] ) : '[]';
			$post_ids      = json_decode( $post_ids_json, true );
			$sync_options  = isset( $this->options['sync'] ) && is_array( $this->options['sync'] ) ? $this->options['sync'] : [];
			$batch_conf    = isset( $sync_options['options'] ) && is_array( $sync_options['options'] ) ? $sync_options['options'] : [];
			$batch_size    = (int) ( $batch_conf['batch_size'] ?? 10 );
			$offset        = ( $page - 1 ) * $batch_size;
			$batch_ids     = array_slice( $post_ids, $offset, $batch_size );

			if ( empty( $batch_ids ) ) {
				ob_end_clean();
				wp_send_json_success( [ 'message' => 'No more posts to process.' ] );
				return;
			}

			// Retrieve post data
			$posts_batch = get_posts(
				[
					'post__in'            => $batch_ids,
					'post_type'           => 'any',
					'orderby'             => 'post__in',
					'posts_per_page'      => count( $batch_ids ),
					'ignore_sticky_posts' => 1,
					'post_status'         => 'publish',
				]
			);

			// Determine language code once per batch.
			$locale    = get_locale();
			$lang_code = strstr( $locale, '_', true ) ?: $locale;

			// Read Free plugin vector store configuration to determine if Qdrant is enabled.
			$free_settings = get_option( 'fe_ai_search_settings', [] );
			$vector_config = isset( $free_settings['vector'] ) && is_array( $free_settings['vector'] ) ? $free_settings['vector'] : [];
			$qdrant_config = isset( $vector_config['qdrant'] ) && is_array( $vector_config['qdrant'] ) ? $vector_config['qdrant'] : [];
			$vector_store  = $vector_config['store'] ?? 'mariadb';

			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'INFO',
				'Qdrant config check in ajax_process_batch.',
				[
					'vector_store'  => $vector_store,
					'vector_config' => $vector_config,
					'qdrant_config' => $qdrant_config,
				]
			);

			$qdrant_enabled = (
				'qdrant' === $vector_store
				&& ! empty( $qdrant_config['endpoint'] )
				&& ! empty( $qdrant_config['api_key'] )
				&& ! empty( $qdrant_config['collection'] )
			);

			// Process each post
			foreach ( $posts_batch as $post ) {
				$chunks_with_meta = $this->create_chunks_from_post( $post );
				if ( empty( $chunks_with_meta ) ) {
					continue;
				}

				$chunks_for_embedding = wp_list_pluck( $chunks_with_meta, 'content_chunk' );
				$embedding_response   = $this->get_embeddings_via_selected_provider( $chunks_for_embedding );

				if ( ! is_wp_error( $embedding_response ) && ! empty( $embedding_response['data'] ) ) {
					$embedding_model = $embedding_response['embedding_model'] ?? '';
					$embedding_dim   = (int) ( $embedding_response['embedding_dim'] ?? 0 );
					global $wpdb;
					$vectors_table = $wpdb->prefix . 'fe_ai_search_vectors';
					$index_table   = $wpdb->prefix . 'fe_ai_search_keyword_index';
					$vectors_data  = $embedding_response['data'];

					foreach ( $vectors_data as $index => $vector_item ) {
						if ( empty( $vector_item['embedding'] ) ) {
							continue;
						}

						$chunk_item = $chunks_with_meta[ $index ];

						$wpdb->insert(
							$vectors_table,
							[
								'post_id'         => $post->ID,
								'lang'            => $lang_code,
								'content_chunk'   => $chunk_item['content_chunk'],
								'vector_data'     => wp_json_encode( $vector_item['embedding'] ),
								'embedding_model' => $embedding_model,
								'embedding_dim'   => $embedding_dim,
								'created_at'      => current_time( 'mysql' ),
							]
						);
						$vector_id = $wpdb->insert_id;

						if ( $vector_id ) {
							$keywords        = $this->tokenize_text( $chunk_item['content_chunk'] );
							$unique_keywords = array_unique( $keywords );

							foreach ( $unique_keywords as $keyword ) {
								if ( mb_strlen( $keyword ) > 1 ) {
									// Use INSERT IGNORE semantics so that duplicate (keyword, vector_id)
									// combinations do not trigger database errors during sync.
									$wpdb->query(
										$wpdb->prepare(
											"INSERT IGNORE INTO `{$index_table}` (`keyword`, `vector_id`, `lang`) VALUES (%s, %d, %s)",
											$keyword,
											$vector_id,
											$lang_code
										)
									);
								}
							}
						}
					}

					// Mirror vectors to Qdrant when enabled in Pro settings.
					if ( $qdrant_enabled ) {
						$this->upsert_vectors_to_qdrant( $post, $lang_code, $chunks_with_meta, $vectors_data, $qdrant_config );
					}
				} else {
				}
			}

			ob_end_clean(); // Discard all unintended outputs even in case of success.
			wp_send_json_success( [ 'message' => 'Batch ' . $page . ' processed.' ] );

		} catch ( \Throwable $t ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			if ( ! headers_sent() ) {
				wp_send_json_error( [ 'message' => 'A fatal error occurred on the server: ' . $t->getMessage() ] );
			}
		}
	}

	/**
	 * Updates the stored timestamp of the last successful sync.
	 *
	 * This timestamp is used as the baseline for smart sync.
	 *
	 * @return void
	 */
	public function ajax_update_sync_timestamp() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		$now = current_time( 'timestamp' );

		// Runtime state is stored in a dedicated option to avoid interference
		// from settings sanitization or other writers of fe_ai_search_settings.
		$state = get_option( 'fe_ai_search_sync_state', [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		if ( ! isset( $state['status'] ) || ! is_array( $state['status'] ) ) {
			$state['status'] = [];
		}
		$state['status']['last_sync_timestamp'] = $now;
		update_option( 'fe_ai_search_sync_state', $state );
		// Keep an in-memory mirror if needed by this request lifecycle.
		$this->options['sync_status'] = $state['status'];

		wp_send_json_success();
	}

	/**
	 * Deletes all vectors and keyword index records.
	 *
	 * Also resets sync-related timestamps and legacy options.
	 *
	 * @return void
	 */
	public function ajax_delete_vectors() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_ai_search_vectors';
		$index_table   = $wpdb->prefix . 'fe_ai_search_keyword_index';

		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		$wpdb->query( "TRUNCATE TABLE `{$index_table}`" );

		// Reset last sync timestamps inside the main settings array.
		$options = $this->options;
		if ( is_array( $options ) && isset( $options['sync'] ) && is_array( $options['sync'] ) ) {
			if ( isset( $options['sync']['status'] ) && is_array( $options['sync']['status'] ) ) {
				$options['sync']['status']['last_sync_timestamp'] = 0;
			}
			if ( isset( $options['sync']['options'] ) && is_array( $options['sync']['options'] ) ) {
				$options['sync']['options']['last_sync_timestamp'] = 0;
			}
			update_option( 'fe_ai_search_settings', $options );
			$this->options = $options;
		}

		wp_send_json_success( __( 'All sync data has been deleted.', 'fe-ai-search' ) );
	}

	/**
	 * Upserts vectors for a single post into Qdrant when configured in Pro settings.
	 *
	 * @param \WP_Post $post             The post being processed.
	 * @param string   $lang_code        Language code (e.g. "en", "ja").
	 * @param array    $chunks_with_meta Array of chunks with permalink metadata.
	 * @param array    $vectors_data     Embedding response data from provider.
	 * @param array    $qdrant_config    Qdrant configuration from Pro settings.
	 * @return void
	 */
	private function upsert_vectors_to_qdrant( $post, $lang_code, $chunks_with_meta, $vectors_data, $qdrant_config ) {
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'Qdrant upsert invoked.',
			[ 'post_id' => $post->ID ]
		);

		$endpoint   = isset( $qdrant_config['endpoint'] ) ? rtrim( $qdrant_config['endpoint'], '/' ) : '';
		$collection = $qdrant_config['collection'] ?? '';
		$api_key    = $qdrant_config['api_key'] ?? '';

		if ( empty( $endpoint ) || empty( $collection ) || empty( $api_key ) ) {
			return;
		}

		$points = [];

		foreach ( $vectors_data as $index => $vector_item ) {
			if ( empty( $vector_item['embedding'] ) || empty( $chunks_with_meta[ $index ] ) ) {
				continue;
			}

			$chunk = $chunks_with_meta[ $index ];
			/**
			 * Filters the maximum length of the content snippet stored in Qdrant payload.
			 *
			 * This controls how many characters from each content chunk are saved
			 * as `content_snippet` when mirroring vectors to Qdrant.
			 *
			 * @param int      $snippet_length Default snippet length in characters.
			 * @param \WP_Post $post           The post being processed.
			 * @param array    $chunk          The chunk data including 'content_chunk' and 'permalink'.
			 */
			$snippet_length = (int) apply_filters( 'fe_ai_search_qdrant_snippet_length', 1000, $post, $chunk );
			if ( $snippet_length <= 0 ) {
				$snippet_length = 1000;
			}

			$points[] = [
				'id'      => (int) ( $post->ID * 10000 + $index ),
				'vector'  => $vector_item['embedding'],
				'payload' => [
					'site_id'         => get_current_blog_id(),
					'post_id'         => $post->ID,
					'permalink'       => $chunk['permalink'] ?? get_permalink( $post ),
					'title'           => get_the_title( $post ),
					'content_snippet' => mb_substr( $chunk['content_chunk'], 0, $snippet_length ),
					'language'        => $lang_code,
					'source'          => 'post',
				],
			];
		}

		if ( empty( $points ) ) {
			return;
		}

		$body = [
			'points' => $points,
		];
		$url  = $endpoint . '/collections/' . rawurlencode( $collection ) . '/points';

		$response = wp_remote_request(
			$url,
			[
				'method'  => 'PUT',
				'headers' => [
					'Content-Type' => 'application/json',
					'api-key'      => $api_key,
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Qdrant upsert request failed.',
				[
					'post_id'       => $post->ID,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				]
			);
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$body_text    = wp_remote_retrieve_body( $response );
			$body_summary = mb_substr( $body_text, 0, 1000 );
			if ( mb_strlen( $body_text ) > 1000 ) {
				$body_summary .= '...(truncated)';
			}
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Qdrant upsert returned non-2xx status.',
				[
					'post_id'       => $post->ID,
					'http_status'   => $status_code,
					'response_body' => $body_summary,
				]
			);
		}
	}

	/**
	 * Retrieves similar chunks from Qdrant using the user's question.
	 *
	 * Uses the selected embedding provider to embed the question, performs
	 * a vector search against the configured Qdrant collection, and returns
	 * an array compatible with the keyword-based find_similar_chunks method.
	 *
	 * @param string $question      The end user's question text.
	 * @param array  $qdrant_config Qdrant configuration from Pro settings.
	 * @return array An array of the most relevant text chunks and their permalinks.
	 */
	private function find_similar_chunks_via_qdrant( $question, $qdrant_config ) {
		$endpoint   = isset( $qdrant_config['endpoint'] ) ? rtrim( $qdrant_config['endpoint'], '/' ) : '';
		$collection = $qdrant_config['collection'] ?? '';
		$api_key    = $qdrant_config['api_key'] ?? '';

		if ( empty( $endpoint ) || empty( $collection ) || empty( $api_key ) ) {
			return [];
		}

		$embedding_response = $this->get_embeddings_via_selected_provider( [ $question ] );
		if ( is_wp_error( $embedding_response ) || empty( $embedding_response['data'][0]['embedding'] ) ) {
			return [];
		}

		$vector = $embedding_response['data'][0]['embedding'];

		$body = [
			'vector'       => $vector,
			'limit'        => 20,
			'with_payload' => true,
			'with_vector'  => false,
		];

		$url = $endpoint . '/collections/' . rawurlencode( $collection ) . '/points/search';

		$response = wp_remote_request(
			$url,
			[
				'method'  => 'POST',
				'headers' => [
					'Content-Type' => 'application/json',
					'api-key'      => $api_key,
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Qdrant search request failed.',
				[
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				]
			);
			return [];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$body_text = wp_remote_retrieve_body( $response );
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Qdrant search returned non-2xx status.',
				[
					'http_status'   => $status_code,
					'response_body' => $body_text,
				]
			);
			return [];
		}

		$body_text = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body_text, true );
		if ( ! is_array( $data ) || empty( $data['result'] ) || ! is_array( $data['result'] ) ) {
			return [];
		}

		$results = [];
		foreach ( $data['result'] as $point ) {
			if ( empty( $point['payload'] ) || ! is_array( $point['payload'] ) ) {
				continue;
			}
			$payload         = $point['payload'];
			$content_snippet = isset( $payload['content_snippet'] ) ? (string) $payload['content_snippet'] : '';
			$permalink       = isset( $payload['permalink'] ) ? (string) $payload['permalink'] : '';

			if ( '' === $content_snippet || '' === $permalink ) {
				continue;
			}

			$results[] = [
				'content_chunk' => $content_snippet,
				'permalink'     => $permalink,
			];
		}

		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'Qdrant search succeeded.',
			[
				'hits'              => count( $results ),
				'sample_permalinks' => array_slice(
					array_column( $results, 'permalink' ),
					0,
					3
				),
			]
		);

		return $results;
	}

	/**
	 * Performs a fast, keyword-based recall of relevant content chunks.
	 *
	 * Extracts keywords from the end user's question, looks up matching
	 * chunks in a regular relational database (without using a vector DB),
	 * and returns up to 500 of the most relevant chunks. The result is
	 * intended to be passed as context to an LLM so that you can obtain
	 * practical-quality answers without incurring heavy vector search
	 * costs or timeouts.
	 *
	 * @param string $question The end user's question text.
	 * @return array An array of the most relevant text chunks and their permalinks.
	 */
	public function find_similar_chunks( $question ) {
		// When Qdrant is enabled in Free settings, prefer vector search via Qdrant.
		$free_settings = get_option( 'fe_ai_search_settings', [] );
		$vector_config = isset( $free_settings['vector'] ) && is_array( $free_settings['vector'] ) ? $free_settings['vector'] : [];
		$qdrant_config = isset( $vector_config['qdrant'] ) && is_array( $vector_config['qdrant'] ) ? $vector_config['qdrant'] : [];
		$vector_store  = $vector_config['store'] ?? 'mariadb';

		$qdrant_enabled = (
			'qdrant' === $vector_store &&
			! empty( $qdrant_config['endpoint'] ) &&
			! empty( $qdrant_config['api_key'] ) &&
			! empty( $qdrant_config['collection'] )
		);

		if ( $qdrant_enabled ) {
			$results = $this->find_similar_chunks_via_qdrant( $question, $qdrant_config );
			if ( ! empty( $results ) ) {
				return $results;
			}
		}

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_ai_search_vectors';
		$index_table   = $wpdb->prefix . 'fe_ai_search_keyword_index';

		// Use the finalized tokenize_text method to extract keywords from the question.
		$keywords = array_unique( $this->tokenize_text( $question ) );

		if ( empty( $keywords ) ) {
			return [];
		}

		// Remove keywords that are too short to be meaningful.
		$valid_keywords = array_filter(
			$keywords,
			function ( $kw ) {
				return mb_strlen( $kw ) > 1;
			}
		);

		// DEBUG: Log keyword extraction results.
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'DEBUG',
			'Keyword extraction from user question.',
			[
				'initial_keywords' => count( $keywords ),
				'valid_keywords'   => count( $valid_keywords ),
				'keywords_list'    => $valid_keywords, // Log the actual keywords
			]
		);

		if ( empty( $valid_keywords ) ) {
			return [];
		}

		// Find vector IDs that match the keywords.
		/**
		 * Filters the maximum number of chunks returned for use as LLM context.
		 *
		 * This controls how many of the most relevant text chunks are retrieved
		 * from the keyword index and ultimately passed to the LLM as context.
		 *
		 * @param int    $max_chunks Default maximum number of chunks.
		 * @param string $question   The end user's question text.
		 */
		$max_chunks = (int) apply_filters( 'fe_ai_search_max_chunks_for_llm', 500, $question );
		if ( $max_chunks <= 0 ) {
			$max_chunks = 500;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $valid_keywords ), '%s' ) );
		$sql          = "SELECT DISTINCT `vector_id` FROM `{$index_table}` WHERE `keyword` IN ( {$placeholders} ) LIMIT {$max_chunks}";
		$vector_ids   = $wpdb->get_col( $wpdb->prepare( $sql, $valid_keywords ) );

		// DEBUG: Log index search results.
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'DEBUG',
			'Keyword index search completed.',
			[
				'keywords_searched'  => $valid_keywords,
				'matched_vector_ids' => count( $vector_ids ),
			]
		);

		if ( empty( $vector_ids ) ) {
			return [];
		}

		// Retrieve the full content chunks for the found vector IDs.
		$placeholders = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
		$sql          = "SELECT `content_chunk`, `post_id` FROM `{$vectors_table}` WHERE `id` IN ( {$placeholders} )";
		$chunks_data  = $wpdb->get_results( $wpdb->prepare( $sql, $vector_ids ), ARRAY_A );

		// Add permalink to the results.
		$results = [];
		foreach ( $chunks_data as $row ) {
			$results[] = [
				'content_chunk' => $row['content_chunk'],
				'permalink'     => get_permalink( $row['post_id'] ),
			];
		}

		return $results;
	}

	/**
	 * Creates text chunks from a post object, combining metadata into prose.
	 *
	 * @param \WP_Post $post The post object to process.
	 * @return array An array of text chunks.
	 */
	public function create_chunks_from_post( $post ) {
		$sync_root  = $this->options['sync'] ?? [];
		$targets    = isset( $sync_root['targets'] ) && is_array( $sync_root['targets'] ) ? $sync_root['targets'] : [];
		$pt_options = $targets[ $post->post_type ] ?? [];
		if ( ! is_array( $pt_options ) ) {
			$pt_options = [];
		}

		// If there is no explicit config for this post type yet (e.g. after schema change),
		// fall back to sensible defaults so that content is actually indexed.
		if ( empty( $pt_options ) ) {
			$pt_options = [
				'enabled' => true,
				'snippet_include_title'   => true,
				'snippet_include_content' => true,
				'snippet_include_date'    => false,
				'snippet_include_author'  => false,
				'snippet_taxonomies'      => [],
				'enable_custom_fields'    => false,
				'snippet_custom_fields'   => '',
			];
		}

		$meta_parts = [];
		$body_text  = '';

		// Resolve snippet flags
		$snippet_title     = ! empty( $pt_options['snippet_include_title'] );
		$snippet_content   = ! empty( $pt_options['snippet_include_content'] );
		$snippet_date      = ! empty( $pt_options['snippet_include_date'] );
		$snippet_author    = ! empty( $pt_options['snippet_include_author'] );
		$snippet_taxonomies = isset( $pt_options['snippet_taxonomies'] ) && is_array( $pt_options['snippet_taxonomies'] ) ? $pt_options['snippet_taxonomies'] : [];

		// Build an array of sentences from metadata.
		if ( $snippet_title ) {
			$meta_parts[] = sprintf( 'The title of this article is "%s".', $post->post_title );
		}
		if ( $snippet_date ) {
			$meta_parts[] = sprintf( 'This article was published on %s.', date_i18n( get_option( 'date_format' ), strtotime( $post->post_date ) ) );
		}
		if ( $snippet_author ) {
			$user = get_user_by( 'ID', $post->post_author );
			if ( $user instanceof \WP_User ) {
				$nickname   = (string) get_the_author_meta( 'nickname', $post->post_author );
				$user_email = (string) $user->user_email;
				$user_login = (string) $user->user_login;

				// If nickname is effectively just an email address or the raw user login/ID,
				// we treat it as non-user-friendly and do NOT include author information
				// in the chunk to avoid exposing personal data.
				$looks_like_email = (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/u', $nickname );
				if (
					! empty( $nickname ) &&
					! $looks_like_email &&
					$nickname !== $user_email &&
					$nickname !== $user_login
				) {
					$meta_parts[] = sprintf( 'The author of this article is %s.', $nickname );
				}
			}
		}

		// Taxonomies with include/exclude behavior and term IDs filter
		if ( ! empty( $snippet_taxonomies ) ) {
			$taxonomy_lines = [];
			foreach ( $snippet_taxonomies as $tax_name => $tax_config ) {
				if ( ! is_array( $tax_config ) ) {
					continue;
				}
				$behavior = $tax_config['behavior'] ?? 'include_terms';
				$term_ids_raw = $tax_config['term_ids'] ?? '';
				$term_ids = [];
				if ( is_string( $term_ids_raw ) && '' !== trim( $term_ids_raw ) ) {
					$term_ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $term_ids_raw ) ) ) );
				}
				$terms = get_the_terms( $post->ID, $tax_name );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$taxonomy = get_taxonomy( $tax_name );
				if ( ! $taxonomy ) {
					continue;
				}
				// Filter terms by behavior and term_ids
				$filtered_terms = [];
				foreach ( $terms as $term ) {
					$term_id = (int) $term->term_id;
					if ( 'include_terms' === $behavior ) {
						if ( empty( $term_ids ) || in_array( $term_id, $term_ids, true ) ) {
							$filtered_terms[] = $term;
						}
					} elseif ( 'exclude_terms' === $behavior ) {
						if ( empty( $term_ids ) || ! in_array( $term_id, $term_ids, true ) ) {
							$filtered_terms[] = $term;
						}
					}
				}
				if ( empty( $filtered_terms ) ) {
					continue;
				}
				$label            = $taxonomy->label;
				$term_names       = wp_list_pluck( $filtered_terms, 'name' );
				$line             = sprintf( '%s=%s', $label, implode( ', ', $term_names ) );
				$taxonomy_lines[] = $line;
			}
			if ( ! empty( $taxonomy_lines ) ) {
				$taxonomy_lines = apply_filters( 'fe_ai_search_taxonomy_lines', $taxonomy_lines, $post, $pt_options );
				$prefix_line    = 'The following taxonomy metadata is associated with this article:';
				$has_prefix     = false;
				foreach ( $taxonomy_lines as $line ) {
					if ( trim( (string) $line ) === $prefix_line ) {
						$has_prefix = true;
						break;
					}
				}
				if ( ! $has_prefix ) {
					$meta_parts[] = $prefix_line;
				}
				foreach ( $taxonomy_lines as $line ) {
					if ( '' !== trim( (string) $line ) ) {
						$meta_parts[] = (string) $line;
					}
				}
			}
		}

		$enable_custom_fields        = ! empty( $pt_options['enable_custom_fields'] );
		$snippet_custom_fields_input = $pt_options['snippet_custom_fields'] ?? '';
		if ( ! FE_AI_Search_License::is_pro_active() ) {
			$enable_custom_fields = false;
		}
		if ( $enable_custom_fields ) {
			// snippet_custom_fields exclusively controls which custom fields are embedded into chunks.
			$raw_input = (string) $snippet_custom_fields_input;
			$raw_input = trim( $raw_input );
			if ( '' !== $raw_input ) {
				// Support commas, newlines, and whitespace as separators.
				$normalized_input = preg_replace( '/[\r\n]+/u', ',', $raw_input );
				$normalized_input = preg_replace( '/\s+/u', ',', $normalized_input );
				$keys_raw         = explode( ',', (string) $normalized_input );
				$keys             = array_filter(
					array_map(
						'sanitize_key',
						array_map( 'trim', $keys_raw )
					)
				);
				$cf_pairs         = [];
				foreach ( $keys as $meta_key ) {
					if ( '' === $meta_key ) {
						continue;
					}
					$value = get_post_meta( $post->ID, $meta_key, true );
					if ( '' === $value || null === $value ) {
						continue;
					}
					$normalized = $this->normalize_custom_field_value( $value );
					if ( '' === $normalized ) {
						continue;
					}
					$cf_pairs[] = sprintf( '%s=%s', $meta_key, $normalized );
				}
				if ( ! empty( $cf_pairs ) ) {
					$custom_fields_line = implode( ', ', $cf_pairs );
					$custom_fields_line = apply_filters( 'fe_ai_search_custom_fields_line', $custom_fields_line, $post, $cf_pairs, $pt_options );
					if ( '' !== trim( (string) $custom_fields_line ) ) {
						$prefix_line = 'The following custom field metadata is associated with this article:';
						if ( 0 !== strpos( trim( (string) $custom_fields_line ), $prefix_line ) ) {
							$meta_parts[] = $prefix_line;
						}
						$meta_parts[] = (string) $custom_fields_line;
					}
				}
			}
		}

		// Add the main content.
		if ( $snippet_content ) {
			$content      = strip_shortcodes( $post->post_content );
			$content      = wp_strip_all_tags( $content );
			$meta_parts[] = 'Here is the main content of the article:';
			$body_text    = $content;
		}

		if ( empty( $meta_parts ) && '' === trim( (string) $body_text ) ) {
			return [];
		}

		// Join metadata into a single block of text.
		$meta_text = implode( "\n", $meta_parts );

		/**
		 * A filter hook for customizing the chunk size used when splitting text.
		 * Hook name: fe_ai_search_chunk_size
		 *
		 * The chunk size controls how much context each piece carries when
		 * creating text chunks for embeddings and keyword indexing.
		 *
		 * @param int      $chunk_size Default chunk size in characters.
		 * @param \WP_Post $post       The post being processed.
		 */
		$chunk_size = (int) apply_filters( 'fe_ai_search_chunk_size', 1000, $post );
		$chunks     = [];
		$meta_text  = trim( (string) $meta_text );
		$body_text  = trim( (string) $body_text );

		// If there is no body, create a single chunk from metadata only.
		if ( '' === $body_text ) {
			$single = $meta_text;
			if ( '' !== $single ) {
				$chunks[] = $single;
			}
		} else {
			// Determine how many characters are available for the body part in each chunk.
			$available_for_body = $chunk_size;
			if ( '' !== $meta_text ) {
				$available_for_body = $chunk_size - mb_strlen( $meta_text ) - 1; // 1 for the newline between meta and body.
			}
			if ( $available_for_body <= 0 ) {
				$available_for_body = 1;
			}

			if ( mb_strlen( $body_text ) <= $available_for_body ) {
				// Everything fits into a single chunk.
				if ( '' !== $meta_text ) {
					$chunks[] = trim( $meta_text . "\n" . $body_text );
				} else {
					$chunks[] = trim( $body_text );
				}
			} else {
				// Split the body into sentence-based chunks, each limited by available_for_body.
				$sentences    = preg_split( '/(?<=[。．！？\n])\s*/u', $body_text, -1, PREG_SPLIT_NO_EMPTY );
				$current_body = '';
				foreach ( $sentences as $sentence ) {
					if ( mb_strlen( $current_body . $sentence ) > $available_for_body ) {
						// Flush the current body chunk.
						if ( '' !== $meta_text ) {
							$chunks[] = trim( $meta_text . "\n" . $current_body );
						} else {
							$chunks[] = trim( $current_body );
						}
						$current_body = $sentence;
					} else {
						$current_body .= $sentence;
					}
				}
				if ( '' !== $current_body ) {
					if ( '' !== $meta_text ) {
						$chunks[] = trim( $meta_text . "\n" . $current_body );
					} else {
						$chunks[] = trim( $current_body );
					}
				}
			}
		}

		$permalink        = get_permalink( $post );
		$chunks_with_meta = [];
		foreach ( $chunks as $chunk_content ) {
			// Add the chunk content and its permalink to the list.
			$chunks_with_meta[] = [
				'content_chunk' => $chunk_content,
				'permalink'     => $permalink,
			];
		}

		return $chunks_with_meta;
	}

	/**
	 * Normalizes a custom field value into a flat, human-readable string.
	 *
	 * Attempts to unserialize string values, flattens arrays/objects recursively
	 * into a comma-separated list, and converts scalar/boolean values to strings.
	 * This is used when embedding custom field values into text chunks so that
	 * LLMs can consume them consistently (e.g. "place=tokyo").
	 *
	 * @param mixed $value Raw meta value retrieved from get_post_meta().
	 * @return string Normalized string representation of the value.
	 */
	private function normalize_custom_field_value( $value ) {
		if ( is_string( $value ) ) {
			$value = maybe_unserialize( $value );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$flat = [];
			foreach ( (array) $value as $sub_value ) {
				$sub_normalized = $this->normalize_custom_field_value( $sub_value );
				if ( '' !== $sub_normalized ) {
					$flat[] = $sub_normalized;
				}
			}
			return implode( ', ', $flat );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Dispatches embedding requests to the selected provider.
	 *
	 * Allows third parties to override the result via a filter hook
	 * before falling back to built-in providers.
	 *
	 * @param array $texts List of text chunks to embed.
	 * @return array|WP_Error Embedding response or error object.
	 */
	public function get_embeddings_via_selected_provider( $texts ) {

		\FEAISearch\Core\fe_ai_search_Logger::log(
			'INFO',
			'Embedding process started.',
			[
				'provider'     => $this->options['provider']['embedding'] ?? 'openai',
				'chunks_count' => count( $texts ),
			]
		);

		$provider = $this->options['provider']['embedding'] ?? 'openai';

		/**
		 * A filter hook for interposing your own embedding provider processing.
		 * Hook name: fe_ai_search_embedding_result_for_{provider slug}
		 *
		 * @param WP_Error|array|null $result Result. If null, the default processing will be performed.
		 * @param array               $texts  Array of text to vectorize.
		 */
		$result = apply_filters( "fe_ai_search_embedding_result_for_{$provider}", null, $texts );

		if ( null !== $result ) {
			return $result;
		}

		switch ( $provider ) {
			case 'google':
				return $this->fetch_embeddings_for_gemini( $texts );
			case 'openai':
			default:
				return $this->fetch_embeddings_for_openai( $texts );
		}
	}

	/**
	 * Requests embeddings from the OpenAI (or compatible) API.
	 *
	 * Uses the configured API key and endpoint and logs failures
	 * and performance metrics.
	 *
	 * @param array $texts Text chunks to embed.
	 * @return array|WP_Error API response body or error object.
	 */
	public function fetch_embeddings_for_openai( $texts ) {
		// Start the timer.
		$start_time = microtime( true );

		$api_key = $this->options['provider']['openai_key'] ?? '';
		if ( empty( $api_key ) ) {
			// Log the error before returning.
			\FEAISearch\Core\FE_AI_Search_Logger::log( 'ERROR', 'OpenAI Embedding API call skipped: API Key is not set.' );
			return new \WP_Error( 'api_key_missing', __( 'The OpenAI API key is not configured.', 'fe-ai-search' ) );
		}

		$api_url = 'https://api.openai.com/v1/embeddings';
		if ( $this->is_license_active ) {
			$provider_root     = isset( $this->options['provider'] ) && is_array( $this->options['provider'] ) ? $this->options['provider'] : [];
			$openai_compatible = isset( $provider_root['openai_compatible'] ) && is_array( $provider_root['openai_compatible'] ) ? $provider_root['openai_compatible'] : [];
			$embedding_conf    = isset( $openai_compatible['embedding'] ) && is_array( $openai_compatible['embedding'] ) ? $openai_compatible['embedding'] : [];
			$custom_endpoint   = $embedding_conf['endpoint'] ?? '';
			if ( ! empty( $custom_endpoint ) ) {
				$api_url = $custom_endpoint;
			}
		}

		$body = [
			'model' => 'text-embedding-3-small',
			'input' => $texts,
		];

		$response = wp_remote_post(
			$api_url,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => json_encode( $body ),
				'timeout' => 60,
			]
		);

		// Calculate the duration in milliseconds.
		$duration = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $response ) ) {
			// Log the connection failure.
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'OpenAI Embedding API call failed.',
				[
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
					'duration_ms'   => $duration,
				]
			);
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $response_code !== 200 ) {
			$error_message = isset( $response_body['error']['message'] )
				? $response_body['error']['message']
				: __( 'Unknown API Error', 'fe-ai-search' );

			// Log the API error.
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'OpenAI Embedding API call failed (API Error).',
				[
					'http_status'   => $response_code,
					'error_message' => $error_message,
					'duration_ms'   => $duration,
				]
			);

			return new WP_Error( 'api_error', __( 'API Error', 'fe-ai-search' ) . ': ' . $error_message );
		}

		$usage = $response_body['usage'] ?? [];

		// Derive embedding metadata for downstream storage.
		$embedding_model = $body['model'] ?? ( $response_body['model'] ?? '' );
		$first_vector    = $response_body['data'][0]['embedding'] ?? [];
		$embedding_dim   = is_array( $first_vector ) ? count( $first_vector ) : 0;

		// Attach normalized metadata keys for internal use.
		$response_body['embedding_model'] = $embedding_model;
		$response_body['embedding_dim']   = $embedding_dim;

		// Log the successful call.
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'OpenAI Embedding API call succeeded.',
			[
				'http_status'     => wp_remote_retrieve_response_code( $response ),
				'duration_ms'     => $duration,
				'total_tokens'    => $usage['total_tokens'] ?? 'N/A',
				'embedding_model' => $embedding_model,
				'embedding_dim'   => $embedding_dim,
			]
		);

		return $response_body;
	}

	/**
	 * Requests embeddings from the Gemini API and normalizes the response.
	 *
	 * Wraps Google Generative Language API for embedding generation
	 * and converts the result to the same format as OpenAI.
	 *
	 * @param array $texts Text chunks to embed.
	 * @return array|WP_Error Normalized response data or error object.
	 */
	public function fetch_embeddings_for_gemini( $texts ) {
		// Start the timer.
		$start_time = microtime( true );

		// Read Google API key from the same schema as the settings page.
		$api_key = $this->options['provider']['google_key'] ?? '';
		if ( empty( $api_key ) ) {
			// Log the error before returning.
			\FEAISearch\Core\FE_AI_Search_Logger::log( 'ERROR', 'Gemini Embedding API call skipped: API Key is not set.' );
			return new \WP_Error( 'api_key_missing', __( 'The Google Cloud API key is not configured.', 'fe-ai-search' ) );
		}

		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:batchEmbedContents?key=' . $api_key;

		// The request body structure is different
		$requests = [];
		foreach ( $texts as $text ) {
			$requests[] = [
				'model'   => 'models/text-embedding-004',
				'content' => [
					'parts' => [
						[ 'text' => $text ],
					],
				],
			];
		}
		$body = [ 'requests' => $requests ];

		$response = wp_remote_post(
			$api_url,
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => json_encode( $body ),
				'timeout' => 60,
			]
		);

		// Calculate the duration in milliseconds.
		$duration = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $response ) ) {
			// Log the connection failure.
			\FEAISearch\Core\fe_ai_search_Logger::log(
				'ERROR',
				'Gemini Embedding API call failed.',
				[
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
					'duration_ms'   => $duration,
				]
			);
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $response_code !== 200 ) {
			$error_message = isset( $response_body['error']['message'] )
				? $response_body['error']['message']
				: __( 'Unknown API Error', 'fe-ai-search' );

			// Log the API error.
			\FEAISearch\Core\fe_ai_search_Logger::log(
				'ERROR',
				'Gemini Embedding API call failed (API Error).',
				[
					'http_status'   => $response_code,
					'error_message' => $error_message,
					'duration_ms'   => $duration,
				]
			);

			return new WP_Error( 'api_error', __( 'API Error', 'fe-ai-search' ) . ': ' . $error_message );
		}

		// The structure for extracting vector data from the response is different
		$vectors = [];
		if ( isset( $response_body['embeddings'] ) ) {
			foreach ( $response_body['embeddings'] as $embedding_data ) {
				$vectors[] = $embedding_data['values'];
			}
		}

		// Normalize the response to match the OpenAI-like structure used elsewhere.
		$normalized_data = [];
		if ( ! empty( $response_body['embeddings'] ) && is_array( $response_body['embeddings'] ) ) {
			foreach ( $response_body['embeddings'] as $index => $embedding ) {
				$normalized_data[] = [
					'index'     => $index,
					'embedding' => $embedding['values'] ?? [],
				];
			}
		}

		$duration        = round( ( microtime( true ) - $start_time ) * 1000 );
		$first_embedding = $normalized_data[0]['embedding'] ?? [];
		$embedding_dim   = is_array( $first_embedding ) ? count( $first_embedding ) : 0;
		$embedding_model = 'text-embedding-004';

		// Log success.
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'Gemini Embedding API call succeeded.',
			[
				'http_status'     => $response_code,
				'duration_ms'     => $duration,
				'count'           => count( $normalized_data ),
				'embedding_model' => $embedding_model,
				'embedding_dim'   => $embedding_dim,
			]
		);

		return [
			'data'            => $normalized_data,
			'embedding_model' => $embedding_model,
			'embedding_dim'   => $embedding_dim,
		];
	}

	/**
	 * Split text into keywords according to language.
	 *
	 * @param  string $text The text to split.
	 * @return array  An array of keywords.
	 */
	public function tokenize_text( $text ) {
		// Normalization
		$text_normalized = mb_strtolower( (string) $text, 'UTF-8' );

		// Use 'asHc' to also convert full-width spaces
		$text_normalized = mb_convert_kana( $text_normalized, 'asHc', 'UTF-8' );

		// Remove all symbols (including asterisk, which was causing issues)
		$text_normalized = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text_normalized );

		$locale     = get_locale();
		$lang_code  = strstr( $locale, '_', true ) ?: $locale;
		$stop_words = [];
		// Load stop words
		// Use the correct plugin directory constant for locating i18n files.
		$lang_file = FE_AI_SEARCH_PLUGIN_DIR . "includes/i18n/{$locale}.php";
		if ( ! file_exists( $lang_file ) ) {
			$lang_file = FE_AI_SEARCH_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
		}
		if ( file_exists( $lang_file ) ) {
			$lang_data  = include $lang_file;
			$stop_words = $lang_data['stop_words'] ?? [];
		}

		/**
		 * A filter hook for customizing the stop-word list used during tokenization.
		 * Hook name: fe_ai_search_stop_words
		 *
		 * This allows site owners and developers to add or remove language-specific
		 * stop words before keyword extraction.
		 *
		 * @param array  $stop_words The default stop-word list loaded for the locale.
		 * @param string $locale     The full locale string (e.g. "en_US", "ja").
		 */
		$stop_words = apply_filters( 'fe_ai_search_stop_words', $stop_words, $locale );

		// Tokenize
		if ( 'ja' === $lang_code ) {
			$tokenizer_engine = $this->get_japanese_tokenizer_engine();
			if ( 'yahoo_ma' === $tokenizer_engine && $this->get_yahoo_app_id() ) {
				$words = $this->tokenize_with_yahoo_api( $text_normalized );
				if ( is_wp_error( $words ) || empty( $words ) ) {
					$words = $this->segmenter->segment( $text_normalized );
				}
			} else {
				$words = $this->segmenter->segment( $text_normalized );
			}
		} else {
			$words = preg_split( '/\s+/', $text_normalized, -1, PREG_SPLIT_NO_EMPTY );
		}

		/**
		 * A filter hook for customizing raw tokens per language before stop-word
		 * removal and stemming.
		 * Hook name: fe_ai_search_tokens_for_lang
		 *
		 * This allows developers to plug in their own tokenization logic for
		 * languages that do not use whitespace-separated words (e.g. Chinese,
		 * Korean), or to otherwise post-process the token list.
		 *
		 * @param array  $words           Raw tokens produced by the built-in tokenizer.
		 * @param string $text_normalized The normalized input text.
		 * @param string $lang_code       Two-letter language code (e.g. 'en', 'ja', 'zh', 'ko').
		 */
		$words = apply_filters( 'fe_ai_search_tokens_for_lang', $words, $text_normalized, $lang_code );

		// Remove stop words
		$words_after_diff = array_diff( (array) $words, (array) $stop_words );

		// Stemming for non-Japanese languages
		if ( 'ja' !== $lang_code ) {
			try {
				$stemmerManager = new \Wamania\Snowball\StemmerManager();
				if ( in_array( $lang_code, $stemmerManager->getAvailableLanguages() ) ) {
					$stemmer       = $stemmerManager->create( $lang_code );
					$stemmed_words = [];
					foreach ( $words_after_diff as $word ) {
						$stemmed_words[] = $stemmer->stem( $word );
					}
					$words_after_diff = $stemmed_words;
				}
			} catch ( \Throwable $t ) {
			}
		}

		// Final Cleanup
		if ( ! empty( $words_after_diff ) ) {
			// Use the correct variable '$word' in the anonymous function
			$words_after_diff = array_filter(
				$words_after_diff,
				function ( $word ) {
					return ! empty( trim( $word ) );
				}
			);
		}

		// Make sure keywords are unique before returning
		$final_words = array_values( array_unique( $words_after_diff ) );

		/**
		 * A filter hook for customizing the final keyword list returned by tokenize_text.
		 * Hook name: fe_ai_search_tokenize_text
		 *
		 * This allows site owners and developers to post-process or augment the
		 * extracted keywords (for example, adding custom synonyms or removing
		 * domain-specific terms) before they are used for indexing or search.
		 *
		 * @param array  $final_words     The final list of keywords.
		 * @param string $text_normalized The normalized input text.
		 * @param string $locale          The full locale string (e.g. "en_US", "ja").
		 */
		$final_words = apply_filters( 'fe_ai_search_tokenize_text', $final_words, $text_normalized, $locale );

		return $final_words;
	}

	private function get_japanese_tokenizer_engine() {
		$tokenizer_root = $this->options['tokenizer'] ?? [];
		if ( ! is_array( $tokenizer_root ) ) {
			$tokenizer_root = [];
		}
		$ja_conf = $tokenizer_root['ja'] ?? [];
		if ( ! is_array( $ja_conf ) ) {
			$ja_conf = [];
		}
		$engine = $ja_conf['engine'] ?? 'tinysegmenter';
		if ( ! in_array( $engine, [ 'tinysegmenter', 'yahoo_ma' ], true ) ) {
			$engine = 'tinysegmenter';
		}
		return $engine;
	}

	private function get_yahoo_app_id() {
		if ( defined( 'FEAS_YAHOO_APP_ID' ) && FEAS_YAHOO_APP_ID ) {
			return FEAS_YAHOO_APP_ID;
		}
		$tokenizer_root = $this->options['tokenizer'] ?? [];
		if ( ! is_array( $tokenizer_root ) ) {
			$tokenizer_root = [];
		}
		$ja_conf = $tokenizer_root['ja'] ?? [];
		if ( ! is_array( $ja_conf ) ) {
			$ja_conf = [];
		}
		$yahoo_id = $ja_conf['yahoo_id'] ?? '';
		return (string) $yahoo_id;
	}

	private function tokenize_with_yahoo_api( $text_normalized ) {
		$app_id = $this->get_yahoo_app_id();
		if ( empty( $app_id ) ) {
			return [];
		}

		$endpoint = 'https://jlp.yahooapis.jp/MAService/V2/parse';
		$body     = [
			'id'      => '1',
			'jsonrpc' => '2.0',
			'method'  => 'jlp.maservice.parse',
			'params'  => [
				'q'           => $text_normalized,
				'ma_response' => 'surface,baseform',
			],
		];

		$debug_mode = ! empty( $this->options['advanced']['debug_mode'] );
		if ( $debug_mode ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'INFO',
				'Yahoo MA API request prepared.',
				[
					'endpoint'     => $endpoint,
					// Since the full text tends to be long, only the beginning is logged.
					'query_length' => mb_strlen( $text_normalized, 'UTF-8' ),
				]
			);
		}

		$response = wp_remote_post(
			$endpoint,
			[
				'headers' => [
					'Content-Type'   => 'application/json',
					'User-Agent'     => 'Yahoo AppID: ' . $app_id,
					'X-Yahoo-App-Id' => $app_id,
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Yahoo MA API call failed.',
				[
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				]
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code || ! isset( $data['result']['tokens'] ) || ! is_array( $data['result']['tokens'] ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Yahoo MA API returned unexpected response.',
				[
					'http_status' => $code,
				]
			);
			return [];
		}

		$words = [];
		foreach ( $data['result']['tokens'] as $token_row ) {
			// According to API docs: [surface, reading, baseform, pos, ...]
			$surface  = $token_row[0] ?? '';
			$baseform = $token_row[2] ?? '';
			$word     = $baseform ?: $surface;
			$word     = (string) $word;
			if ( '' !== $word ) {
				$words[] = $word;
			}
		}

		if ( $debug_mode ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'INFO',
				'Yahoo MA API call succeeded.',
				[
					'http_status' => $code,
					'token_count' => count( $words ),
				]
			);
		}

		return $words;
	}

	/**
	 * Renders an administrator notification about internationalization
	 */
	public function render_i18n_notice() {
		error_log( 'FEAS i18n: transient=' . ( get_transient( 'fe_ai_search_i18n_notice_dismissed' ) ? '1' : '0' ) . ' locale=' . get_locale() );
		// Do not show the notice if it was recently dismissed.
		if ( get_transient( 'fe_ai_search_i18n_notice_dismissed' ) ) {
			return;
		}

		// Only show the notice when there is no bundled i18n file
		// for the current locale or its language code.
		$locale    = get_locale();
		$lang_code = strstr( $locale, '_', true ) ?: $locale;
		$lang_file = FE_AI_SEARCH_PLUGIN_DIR . "includes/i18n/{$locale}.php";
		if ( ! file_exists( $lang_file ) ) {
			$lang_file = FE_AI_SEARCH_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
		}
		if ( file_exists( $lang_file ) ) {
			return;
		}

		// What happens when a user clicks on a "hidden" link?
		if ( isset( $_GET['fe-ai-search-dismiss-i18n-notice'] ) ) {
			// Flag to hide this notification for one week
			set_transient( 'fe_ai_search_i18n_notice_dismissed', true, WEEK_IN_SECONDS );
			return;
		}
		?>
		<div class="notice notice-info is-dismissible" data-dismiss-url="<?php echo esc_url( add_query_arg( 'fe-ai-search-dismiss-i18n-notice', '1' ) ); ?>">
			<p>
				<b>FE AI Search:</b> <?php esc_html_e( 'Your site language is not fully optimized for keyword search. We welcome contributions for new languages!', 'fe-ai-search' ); ?>
				<a href="https://github.com/firstelementjp/fe-ai-search" target="_blank" style="margin-left: 10px;"><?php esc_html_e( 'Contribute on GitHub', 'fe-ai-search' ); ?></a>
			</p>
		</div>
		<script>
			// Handle dismissing the notice and flagging it via AJAX without reloading the page.
			document.addEventListener('DOMContentLoaded', function () {
				var notice = document.querySelector('div[data-dismiss-url]');
				if (!notice) {
					return;
				}
				var dismissButton = notice.querySelector('.notice-dismiss');
				if (!dismissButton) {
					return;
				}
				dismissButton.addEventListener('click', function (e) {
					e.preventDefault();
					try {
						var formData = new URLSearchParams();
						formData.append('action', 'dismiss-wp-pointer');
						formData.append('pointer', 'fe_ai_search_i18n_notice_dismissed');
						if (typeof ajaxurl !== 'undefined') {
							fetch(ajaxurl, {
								method: 'POST',
								body: formData
								// No need to await; fire-and-forget is fine for this notification.
							});
						}
					} catch (error) {
						// Silently ignore errors; the notice will still be hidden locally.
					}
					notice.style.display = 'none';
				});
			});
		</script>
		<?php
	}

	/**
	 * Adds the Japanese tokenizer status to the sync page UI.
	 *
	 * This method hooks into the 'fe_ai_search_tokenizer_status' filter.
	 *
	 * @since 1.0.0
	 * @param array $statuses The existing status messages.
	 * @return array The modified status messages.
	 */
	public function add_japanese_tokenizer_status( $statuses ) {
		if ( 'ja' !== get_locale() ) {
			return $statuses;
		}

		$status_label = '<strong>' . esc_html__( 'Japanese Tokenizer Status', 'fe-ai-search' ) . ':</strong>';
		$engine       = $this->get_japanese_tokenizer_engine();
		$yahoo_id     = $this->get_yahoo_app_id();
		if ( 'yahoo_ma' === $engine && ! empty( $yahoo_id ) ) {
			$this->japanese_tokenizer = 'yahoo_ma';
			$status_text              = '<span style="color:#46b450;">' . esc_html__( 'Yahoo! Japanese MA API (App ID configured)', 'fe-ai-search' ) . '</span>';
		} elseif ( 'yahoo_ma' === $engine ) {
			$this->japanese_tokenizer = 'yahoo_ma';
			$status_text              = '<span style="color:#dc3232;">' . esc_html__( 'Yahoo! Japanese MA API (App ID missing)', 'fe-ai-search' ) . '</span>';
		} else {
			$this->japanese_tokenizer = 'tinysegmenter';
			$status_text              = '<span style="color:#a0a5aa;">' . esc_html__( 'Built-in (TinySegmenter)', 'fe-ai-search' ) . '</span>';
		}

		/**
		 * Filter the Japanese tokenizer status HTML shown in the Sync page.
		 *
		 * This allows developers to replace the default MeCab/TinySegmenter
		 * label with their own description (for example, when providing a
		 * custom tokenizer implementation via other hooks).
		 *
		 * @since 1.0.0
		 *
		 * @param string $status_text        The HTML snippet describing the tokenizer.
		 * @param string $japanese_tokenizer The current tokenizer identifier (e.g. 'tinysegmenter', 'mecab').
		 */
		$status_text = apply_filters( 'fe_ai_search_japanese_tokenizer_status_text', $status_text, $this->japanese_tokenizer );

		$statuses[] = $status_label . ' ' . $status_text;
		return $statuses;
	}
}
