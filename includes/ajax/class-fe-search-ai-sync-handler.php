<?php
/**
 * Handles all content synchronization and data retrieval tasks.
 *
 * This file defines the fe_search_ai_Sync_Handler class, which is responsible for
 * all processes related to indexing content, creating vector embeddings, and
 * retrieving relevant information from the database for the chat handler.
 *
 * @package    fe-search-ai
 * @subpackage Ajax
 * @since 0.9.0
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use U7aro\TinySegmenter\TinySegmenter;
use WP_Error;

/**
 * Main controller for all data synchronization processes.
 *
 * This class manages both manual (AJAX-based) and real-time (hook-based)
 * content synchronization. It also provides the core search method (`find_similar_chunks`)
 * used by other classes to retrieve contextually relevant data.
 *
 * @since 0.9.0
 * @package    fe-search-ai
 * @subpackage Ajax
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Sync_Handler {

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * License activation status.
	 *
	 * @var bool
	 */
	private $is_license_active;

	/**
	 * TinySegmenter instance or null if PHP < 8.0.
	 *
	 * @var \U7aro\TinySegmenter\TinySegmenter|null
	 */
	private $segmenter;

	/**
	 * Japanese tokenizer engine identifier.
	 *
	 * @var string
	 */
	private $japanese_tokenizer = 'tinysegmenter';

	/**
	 * Initializes options, tokenizer selection, license status, and hooks.
	 *
	 * @return void
	 */
	public function __construct() {

		$this->options = get_option( 'fe_search_ai_settings', [] );

		// Initialize TinySegmenter only if PHP >= 8.0.
		// For PHP < 8.0, Yahoo! MA API must be used for Japanese tokenization.
		if ( version_compare( PHP_VERSION, '8.0.0', '>=' ) ) {
			$this->segmenter = new TinySegmenter();
		} else {
			$this->segmenter = null;
		}

		$license_data = get_option( 'fe_search_ai_license', [] );
		$status       = $license_data['status'] ?? 'inactive';
		$data         = $license_data['data'] ?? [];
		$product_id   = isset( $data['productId'] ) ? (int) $data['productId'] : 0;

		// Set license status for use in various methods.
		// Active when the status is "active" and the product ID matches the Pro add-on.
		$this->is_license_active = ( 'active' === $status && 65 === $product_id );

		// $this->is_license_active = true; // Debug override

		add_filter( 'fe_search_ai_tokenizer_status', [ $this, 'add_japanese_tokenizer_status' ] );
		add_action( 'wp_ajax_fe_search_ai_start_sync', [ $this, 'ajax_start_sync' ] );
		add_action( 'wp_ajax_fe_search_ai_process_batch', [ $this, 'ajax_process_batch' ] );
		add_action( 'wp_ajax_fe_search_ai_update_sync_timestamp', [ $this, 'ajax_update_sync_timestamp' ] );
		add_action( 'wp_ajax_fe_search_ai_delete_vectors', [ $this, 'ajax_delete_vectors' ] );
		add_action( 'wp_ajax_fe_search_ai_start_smart_sync', [ $this, 'ajax_start_smart_sync' ] );
		add_action( 'wp_ajax_fe_search_ai_update_settings_hash', [ $this, 'ajax_update_settings_hash' ] );

		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'render_i18n_notice' ] );
		}
	}

	/**
	 * Builds embedding input texts from chunks.
	 *
	 * When the sync option "use_summary_for_embedding" is enabled, this method
	 * generates a normalized summary per chunk using the selected chat provider
	 * and uses that summary as the embedding input. Summaries are stored for
	 * later inspection and troubleshooting.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post             The post being processed.
	 * @param string   $lang_code         Language code (e.g. "en", "ja").
	 * @param array    $chunks_with_meta  Chunk array as returned by create_chunks_from_post().
	 * @return array{embedding_texts: array, summaries: array, summary_hashes: array}
	 */
	public function prepare_embedding_texts_from_chunks( $post, $lang_code, $chunks_with_meta ) {
		$options     = get_option( 'fe_search_ai_settings', [] );
		$sync_root   = isset( $options['sync'] ) && is_array( $options['sync'] ) ? $options['sync'] : [];
		$use_summary = ! empty( $sync_root['use_summary_for_embedding'] );

		$embedding_texts = [];
		$summaries       = [];
		$summary_hashes  = [];

		foreach ( $chunks_with_meta as $index => $chunk_item ) {
			$chunk_text = isset( $chunk_item['content_chunk'] ) ? (string) $chunk_item['content_chunk'] : '';
			if ( '' === trim( $chunk_text ) ) {
				$embedding_texts[ $index ] = '';
				$summaries[ $index ]       = '';
				$summary_hashes[ $index ]  = '';
				continue;
			}

			if ( ! $use_summary ) {
				$embedding_texts[ $index ] = $chunk_text;
				$summaries[ $index ]       = '';
				$summary_hashes[ $index ]  = '';
				continue;
			}

			$summary_hash = md5( $chunk_text );
			$summary      = $this->generate_summary_for_chunk( $chunk_item, $post, $lang_code );
			$summary      = is_string( $summary ) ? trim( $summary ) : '';

			if ( '' === $summary ) {
				// Fail safe: fall back to the original chunk for embedding.
				$embedding_texts[ $index ] = $chunk_text;
				$summaries[ $index ]       = '';
				$summary_hashes[ $index ]  = '';
				continue;
			}

			$embedding_texts[ $index ] = $summary;
			$summaries[ $index ]       = $summary;
			$summary_hashes[ $index ]  = $summary_hash;
		}

		return [
			'embedding_texts' => $embedding_texts,
			'summaries'       => $summaries,
			'summary_hashes'  => $summary_hashes,
		];
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
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_search_ai_vectors';
		$index_table   = $wpdb->prefix . 'fe_search_ai_keyword_index';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Table name is interpolated but controlled internally.
		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Table name is interpolated but controlled internally.
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
					$include_title   = (bool) ( $pt_options['snippet_include_title'] ?? $pt_options['include_title'] ?? false );
					$include_content = (bool) ( $pt_options['snippet_include_content'] ?? $pt_options['include_content'] ?? false );
					$include_date    = (bool) ( $pt_options['snippet_include_date'] ?? $pt_options['include_date'] ?? false );
					$include_author  = (bool) ( $pt_options['snippet_include_author'] ?? $pt_options['include_author'] ?? false );

					// Skip if no Include in Chunk Data options are selected
					$has_any_snippet = (
					$include_title ||
					$include_content ||
					$include_date ||
					$include_author ||
					! empty(
						array_filter(
							array_map(
								function ( $tax_config ) {
									return ! empty( $tax_config['enabled'] );
								},
								$pt_options['snippet_taxonomies'] ?? []
							)
						)
					)
					);
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					// Hook name is properly prefixed with fe_search_ai_.
					$has_any_snippet = (bool) apply_filters( 'fe_search_ai_sync_target_has_snippet', $has_any_snippet, $pt_options, $pt_slug );
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
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			// Exclusion is necessary for user-specified post IDs to exclude from sync.
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
		 * @since 0.9.0
		 *
		 * @param array $args The array of query arguments passed to `get_posts()`.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$args = apply_filters( 'fe_search_ai_sync_query_args', $args );

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
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		// Use the new sync schema for static configuration: enabled post types,
		// limits, etc. are stored under fe_search_ai_settings['sync'].
		$sync_root = $this->options['sync'] ?? [];
		if ( ! is_array( $sync_root ) ) {
			$sync_root = [];
		}
		$targets    = isset( $sync_root['targets'] ) && is_array( $sync_root['targets'] ) ? $sync_root['targets'] : [];
		$sync_limit = isset( $sync_root['limit'] ) ? (int) $sync_root['limit'] : -1;

		// Runtime state (status/settings) is stored separately in
		// fe_search_ai_sync_state to avoid interference from settings sanitization
		// or other code paths that update fe_search_ai_settings.
		$sync_state         = get_option( 'fe_search_ai_sync_state', [] );
		$sync_status        = isset( $sync_state['status'] ) && is_array( $sync_state['status'] ) ? $sync_state['status'] : [];
		$state_settings     = isset( $sync_state['settings'] ) && is_array( $sync_state['settings'] ) ? $sync_state['settings'] : [];
		$post_types_to_sync = [];
		if ( ! empty( $targets ) ) {
			foreach ( $targets as $pt_slug => $pt_options ) {
				if ( ! empty( $pt_options['enabled'] ) ) {
					// Skip if no Include in Chunk Data options are selected
					$has_any_snippet = (
					! empty( $pt_options['include_title'] ) ||
					! empty( $pt_options['include_content'] ) ||
					! empty( $pt_options['include_date'] ) ||
					! empty( $pt_options['include_author'] ) ||
					! empty(
						array_filter(
							array_map(
								function ( $tax_config ) {
									return ! empty( $tax_config['enabled'] );
								},
								$pt_options['snippet_taxonomies'] ?? []
							)
						)
					)
					);
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					// Hook name is properly prefixed with fe_search_ai_.
					$has_any_snippet = (bool) apply_filters( 'fe_search_ai_sync_target_has_snippet', $has_any_snippet, $pt_options, $pt_slug );
					if ( $has_any_snippet ) {
						$post_types_to_sync[] = $pt_slug;
					}
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
			wp_send_json_error( [ 'message' => __( 'Sync settings have changed. Please use "Rebuild All" to apply the new settings.', 'fe-search-ai' ) ] );
		}

		// Find deleted posts
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_search_ai_vectors';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore PluginCheck.Security.PreparedSQLInterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
		// phpcs:ignore PluginCheck.Security.NoCaching
		// Table name is interpolated but controlled internally.
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
				$GLOBALS['fe_search_ai_sync_hooks']->delete_post_from_index( $post_id );
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
					'message'     => __( 'No previous sync baseline found. Please run "Rebuild Index" once before using "Sync Changes".', 'fe-search-ai' ),
				]
			);
		}
		// Convert the stored local timestamp to a GMT datetime string for post_modified_gmt.
		$last_sync_local_datetime = gmdate( 'Y-m-d H:i:s', $last_sync );
		$last_sync_gmt_timestamp  = get_gmt_from_date( $last_sync_local_datetime, 'U' );
		$last_sync_gmt_datetime   = gmdate( 'Y-m-d H:i:s', $last_sync_gmt_timestamp );

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
		// update_option( 'fe_search_ai_last_settings_hash', $current_settings_hash );

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
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		// Pull the freshest static sync configuration from the main settings.
		$options   = get_option( 'fe_search_ai_settings', [] );
		$sync_root = isset( $options['sync'] ) && is_array( $options['sync'] ) ? $options['sync'] : [];

		// Derive the same settings snapshot used in ajax_start_smart_sync by
		// removing runtime/state fields before hashing.
		$settings_root = $sync_root;
		unset( $settings_root['status'], $settings_root['settings'] );
		$current_settings_hash = md5( serialize( $settings_root ) );

		// Runtime state (status/settings) is stored in a dedicated option so that
		// it is not affected by settings sanitization or other writers.
		$state = get_option( 'fe_search_ai_sync_state', [] );
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

		update_option( 'fe_search_ai_sync_state', $state );

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
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			// Required for long-running batch processing.
			set_time_limit( 0 );
			ob_start();

			check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

			// Process input data
			$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// Input is JSON string, validated by json_decode().
			$post_ids_json = isset( $_POST['post_ids'] ) ? wp_unslash( $_POST['post_ids'] ) : '[]';
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

			// Read vector store configuration to determine if Qdrant is enabled.
			$settings      = get_option( 'fe_search_ai_settings', [] );
			$vector_config = isset( $settings['vector'] ) && is_array( $settings['vector'] ) ? $settings['vector'] : [];
			$qdrant_config = isset( $vector_config['qdrant'] ) && is_array( $vector_config['qdrant'] ) ? $vector_config['qdrant'] : [];
			$vector_store  = $vector_config['store'] ?? 'mariadb';
			$stores        = isset( $vector_config['stores'] ) && is_array( $vector_config['stores'] ) ? $vector_config['stores'] : [];
			$qdrant_store  = isset( $stores['qdrant'] ) ? ! empty( $stores['qdrant'] ) : ( 'qdrant' === $vector_store );

			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'INFO',
				'Qdrant config check in ajax_process_batch.',
				[
					'vector_store'  => $vector_store,
					'stores'        => $stores,
					'vector_config' => $vector_config,
					'qdrant_config' => $qdrant_config,
					'collection'    => $qdrant_config['collection'] ?? 'NOT_SET',
					'endpoint'      => $qdrant_config['endpoint'] ?? 'NOT_SET',
					'api_key_set'   => ! empty( $qdrant_config['api_key'] ),
				]
			);

			$qdrant_enabled = (
				$qdrant_store
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

				$prepared        = $this->prepare_embedding_texts_from_chunks( $post, $lang_code, $chunks_with_meta );
				$embedding_texts = $prepared['embedding_texts'] ?? [];
				$summaries       = $prepared['summaries'] ?? [];
				$summary_hashes  = $prepared['summary_hashes'] ?? [];
				foreach ( $chunks_with_meta as $i => $chunk_item ) {
					$chunks_with_meta[ $i ]['summary_text'] = isset( $summaries[ $i ] ) ? (string) $summaries[ $i ] : '';
				}
				$embedding_texts    = array_values( $embedding_texts );
				$embedding_response = $this->get_embeddings_via_selected_provider( $embedding_texts );

				if ( ! is_wp_error( $embedding_response ) && ! empty( $embedding_response['data'] ) ) {
					$embedding_model = $embedding_response['embedding_model'] ?? '';
					$embedding_dim   = (int) ( $embedding_response['embedding_dim'] ?? 0 );
					global $wpdb;
					$vectors_table = $wpdb->prefix . 'fe_search_ai_vectors';
					$index_table   = $wpdb->prefix . 'fe_search_ai_keyword_index';
					$vectors_data  = $embedding_response['data'];

					foreach ( $vectors_data as $index => $vector_item ) {
						if ( empty( $vector_item['embedding'] ) ) {
							continue;
						}

						$chunk_item   = $chunks_with_meta[ $index ];
						$summary_text = isset( $summaries[ $index ] ) ? (string) $summaries[ $index ] : '';
						$summary_hash = isset( $summary_hashes[ $index ] ) ? (string) $summary_hashes[ $index ] : '';

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
						// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
						// Direct insert required for custom table. Table name is controlled internally.
						$wpdb->insert(
							$vectors_table,
							[
								'post_id'         => $post->ID,
								'lang'            => $lang_code,
								'chunk_index'     => $index,
								'content_chunk'   => $chunk_item['content_chunk'],
								'summary_text'    => $summary_text,
								'summary_hash'    => $summary_hash,
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
									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
									// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
									// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
									// phpcs:ignore PluginCheck.Security.PreparedSQLInterpolatedNotPrepared
									// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
									// phpcs:ignore PluginCheck.Security.NoCaching
									// Table name is interpolated but controlled internally, values are prepared.
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
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		$now = current_time( 'timestamp' );

		// Runtime state is stored in a dedicated option to avoid interference
		// from settings sanitization or other writers of fe_search_ai_settings.
		$state = get_option( 'fe_search_ai_sync_state', [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		if ( ! isset( $state['status'] ) || ! is_array( $state['status'] ) ) {
			$state['status'] = [];
		}
		$state['status']['last_sync_timestamp'] = $now;
		update_option( 'fe_search_ai_sync_state', $state );
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
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_search_ai_vectors';
		$index_table   = $wpdb->prefix . 'fe_search_ai_keyword_index';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Table name is interpolated but controlled internally.
		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Table name is interpolated but controlled internally.
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
			update_option( 'fe_search_ai_settings', $options );
			$this->options = $options;
		}

		wp_send_json_success( __( 'All sync data has been deleted.', 'fe-search-ai' ) );
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
		\FESearchAI\Core\FE_Search_AI_Logger::log(
			'INFO',
			'Qdrant upsert invoked.',
			[ 'post_id' => $post->ID ]
		);

		$endpoint   = isset( $qdrant_config['endpoint'] ) ? rtrim( $qdrant_config['endpoint'], '/' ) : '';
		$collection = $qdrant_config['collection'] ?? '';
		$api_key    = isset( $qdrant_config['api_key'] ) && ! empty( $qdrant_config['api_key'] )
			? \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $qdrant_config['api_key'] )
			: '';

		if ( empty( $endpoint ) || empty( $collection ) || empty( $api_key ) ) {
			return;
		}

		$points = [];
		foreach ( $vectors_data as $index => $vector_item ) {
			if ( empty( $vector_item['embedding'] ) || empty( $chunks_with_meta[ $index ] ) ) {
				continue;
			}

			$chunk        = $chunks_with_meta[ $index ];
			$summary_text = isset( $chunk['summary_text'] ) ? (string) $chunk['summary_text'] : '';
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			// Hook name is properly prefixed with fe_search_ai_.
			$snippet_length = (int) apply_filters( 'fe_search_ai_qdrant_snippet_length', 1000, $post, $chunk );
			if ( $snippet_length <= 0 ) {
				$snippet_length = 1000;
			}

			$content_for_snippet = '';
			if ( '' !== $summary_text ) {
				$content_for_snippet = $summary_text;
			} elseif ( isset( $chunk['content_chunk'] ) ) {
				$content_for_snippet = (string) $chunk['content_chunk'];
			}

			$points[] = [
				'id'      => (int) ( $post->ID * 10000 + $index ),
				'vector'  => $vector_item['embedding'],
				'payload' => [
					'site_id'         => get_current_blog_id(),
					'post_id'         => $post->ID,
					'permalink'       => $chunk['permalink'] ?? get_permalink( $post ),
					'title'           => get_the_title( $post ),
					'content_snippet' => mb_substr( $content_for_snippet, 0, $snippet_length ),
					'summary'         => $summary_text,
					'language'        => $lang_code,
					'source'          => 'post',
				],
			];
		}

		if ( empty( $points ) ) {
			return;
		}

		$body     = [ 'points' => $points ];
		$url      = $endpoint . '/collections/' . rawurlencode( $collection ) . '/points';
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$body_text    = wp_remote_retrieve_body( $response );
			$body_summary = mb_substr( $body_text, 0, 1000 );
			if ( mb_strlen( $body_text ) > 1000 ) {
				$body_summary .= '...(truncated)';
			}
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
	 * Generates a normalized summary for a single chunk.
	 *
	 * @since 1.1.0
	 *
	 * @param array    $chunk_item Chunk item.
	 * @param \WP_Post $post      The post.
	 * @param string   $lang_code Language code.
	 * @return string|WP_Error
	 */
	private function generate_summary_for_chunk( $chunk_item, $post, $lang_code ) {
		$raw_chunk = isset( $chunk_item['content_chunk'] ) ? (string) $chunk_item['content_chunk'] : '';
		if ( '' === trim( $raw_chunk ) ) {
			return '';
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$override = apply_filters( 'fe_search_ai_chunk_summary', null, $chunk_item, $post, $lang_code );
		if ( null !== $override ) {
			return is_string( $override ) ? $override : '';
		}
		$context = $this->build_summary_prompt_context( $chunk_item, $post, $lang_code );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$context = apply_filters( 'fe_search_ai_chunk_summary_prompt_context', $context, $chunk_item, $post, $lang_code );
		$prompt  = $this->build_default_summary_prompt( $context, $lang_code );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$prompt = apply_filters( 'fe_search_ai_chunk_summary_generation_prompt', $prompt, $context, $chunk_item, $post, $lang_code );
		$result = $this->request_chat_completion_for_summary( $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$summary = trim( (string) $result );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$summary = apply_filters( 'fe_search_ai_chunk_summary_output_postprocess', $summary, $chunk_item, $post, $lang_code );
		return trim( (string) $summary );
	}

	/**
	 * @since 1.1.0
	 * @param array    $chunk_item Chunk item.
	 * @param \WP_Post $post      The post.
	 * @param string   $lang_code Language code.
	 * @return array{attr:string, content:string}
	 */
	private function build_summary_prompt_context( $chunk_item, $post, $lang_code ) {
		$raw_chunk = isset( $chunk_item['content_chunk'] ) ? (string) $chunk_item['content_chunk'] : '';
		$lines     = preg_split( '/\r\n|\r|\n/u', $raw_chunk );
		$attr      = [];
		$content   = [];
		$in_body   = false;
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 'Content:' === $line ) {
				$in_body = true;
				continue;
			}
			if ( ! $in_body && preg_match( '/^(ID|Title|URL|Date|Metadata):\s*(.*)$/u', $line, $m ) ) {
				$key    = strtolower( (string) $m[1] );
				$value  = (string) $m[2];
				$attr[] = sprintf( 'ATTR: %s=%s', $key, $value );
				continue;
			}
			if ( $in_body ) {
				$content[] = $line;
			}
		}
		if ( empty( $attr ) ) {
			$attr[] = 'ATTR: post_id=' . ( isset( $chunk_item['post_id'] ) ? (string) $chunk_item['post_id'] : (string) $post->ID );
			$attr[] = 'ATTR: title=' . ( isset( $chunk_item['title'] ) ? (string) $chunk_item['title'] : (string) $post->post_title );
			$attr[] = 'ATTR: permalink=' . ( isset( $chunk_item['permalink'] ) ? (string) $chunk_item['permalink'] : (string) get_permalink( $post ) );
		}
		return [
			'attr'    => implode( "\n", $attr ),
			'content' => implode( "\n", $content ),
		];
	}

	/**
	 * @since 1.1.0
	 * @param array  $context  Prompt context.
	 * @param string $lang_code Language code.
	 * @return string
	 */
	private function build_default_summary_prompt( $context, $lang_code ) {
		$attr          = isset( $context['attr'] ) ? (string) $context['attr'] : '';
		$content       = isset( $context['content'] ) ? (string) $context['content'] : '';
		$language_line = '';
		if ( '' !== $lang_code ) {
			$language_line = "- Output language: Use the same language as the input. If unclear, use {$lang_code}.";
		}
		return "You are a text normalizer for semantic search embeddings.\n"
			. "Your task is to create a compact, factual, structured summary for embedding.\n\n"
			. "Rules:\n"
			. "- Do NOT guess or infer missing facts.\n"
			. "- If a value is not present in ATTR or CONTENT, write '不明'.\n"
			. "- Avoid marketing language and opinions.\n"
			. "- Keep sentences short. Prefer bullet-like lines.\n"
			. "- Always output the required fields in the exact order.\n"
			. ( $language_line ? "{$language_line}\n" : '' )
			. "\nINPUT ATTRIBUTES:\n{$attr}\n\n"
			. "INPUT CONTENT:\n{$content}\n\n"
			. "OUTPUT FORMAT (exact labels):\n"
			. "Title: ...\n"
			. "Main topic: ...\n"
			. "Key facts:\n- ...\n- ...\n"
			. "Entities: ...\n"
			. "Audience/Target: ...\n"
			. "Constraints/Requirements: ...\n"
			. "Categories/Tags: ...\n"
			. "Keywords: kw1, kw2, ...\n"
			. "Summary: ...\n";
	}

	/**
	 * @since 1.1.0
	 * @param string $prompt Prompt.
	 * @return string|WP_Error
	 */
	private function request_chat_completion_for_summary( $prompt ) {
		$provider = isset( $this->options['provider']['chat'] ) ? (string) $this->options['provider']['chat'] : 'openai';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$override = apply_filters( 'fe_search_ai_summary_chat_result', null, $prompt, $provider );
		if ( null !== $override ) {
			return is_string( $override ) ? $override : '';
		}
		switch ( $provider ) {
			case 'google':
				return $this->request_summary_via_gemini( $prompt );
			case 'anthropic':
				return $this->request_summary_via_claude( $prompt );
			case 'openai':
			default:
				return $this->request_summary_via_openai( $prompt );
		}
	}

	/**
	 * @since 1.1.0
	 * @param string $prompt Prompt.
	 * @return string|WP_Error
	 */
	private function request_summary_via_openai( $prompt ) {
		$encrypted_key = $this->options['provider']['openai_key'] ?? '';
		$api_key       = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', __( 'The OpenAI API key is not configured.', 'fe-search-ai' ) );
		}
		$model    = 'gpt-5.4-mini';
		$body     = [
			'model'       => $model,
			'messages'    => [
				[ 'role' => 'system', 'content' => 'You are a helpful assistant.' ],
				[ 'role' => 'user', 'content' => $prompt ],
			],
			'temperature' => 0.1,
		];
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 60,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			return new WP_Error( 'api_error', __( 'API Error', 'fe-search-ai' ) );
		}
		$text = $data['choices'][0]['message']['content'] ?? '';
		return is_string( $text ) ? $text : '';
	}

	/**
	 * @since 1.1.0
	 * @param string $prompt Prompt.
	 * @return string|WP_Error
	 */
	private function request_summary_via_gemini( $prompt ) {
		$encrypted_key = $this->options['provider']['google_key'] ?? '';
		$api_key       = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', __( 'The Google Cloud API key is not configured.', 'fe-search-ai' ) );
		}
		$model    = 'gemini-2.5-flash';
		$url      = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
			rawurlencode( $model ),
			rawurlencode( $api_key )
		);
		$body     = [
			'contents'         => [
				[ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ],
			],
			'generationConfig' => [ 'temperature' => 0.1 ],
		];
		$response = wp_remote_post(
			$url,
			[ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $body ), 'timeout' => 60 ]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			return new WP_Error( 'api_error', __( 'API Error', 'fe-search-ai' ) );
		}
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		return is_string( $text ) ? $text : '';
	}

	/**
	 * @since 1.1.0
	 * @param string $prompt Prompt.
	 * @return string|WP_Error
	 */
	private function request_summary_via_claude( $prompt ) {
		$encrypted_key = $this->options['provider']['anthropic_key'] ?? '';
		$api_key       = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', __( 'The Anthropic API key is not configured.', 'fe-search-ai' ) );
		}
		$model    = 'claude-haiku-4-5-20251001';
		$body     = [
			'model'       => $model,
			'max_tokens'  => 1024,
			'temperature' => 0.1,
			'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
		];
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			[
				'headers' => [
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 60,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			return new WP_Error( 'api_error', __( 'API Error', 'fe-search-ai' ) );
		}
		$blocks = $data['content'] ?? [];
		if ( is_array( $blocks ) && ! empty( $blocks[0]['text'] ) ) {
			return (string) $blocks[0]['text'];
		}
		return '';
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
		// Get sequence ID from global context
		$sequence_id = '';
		if ( isset( $GLOBALS['fe_search_ai_current_sequence_id'] ) ) {
			$sequence_id = $GLOBALS['fe_search_ai_current_sequence_id'];
		}

		$endpoint   = isset( $qdrant_config['endpoint'] ) ? rtrim( $qdrant_config['endpoint'], '/' ) : '';
		$collection = $qdrant_config['collection'] ?? '';
		$api_key    = isset( $qdrant_config['api_key'] ) && ! empty( $qdrant_config['api_key'] )
			? \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $qdrant_config['api_key'] )
			: '';

		if ( empty( $endpoint ) || empty( $collection ) || empty( $api_key ) ) {
			return new WP_Error( 'qdrant_error', 'Qdrant configuration is missing.' );
		}

		// Log embedding generation start
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
			'INFO',
			'Starting embedding generation for Qdrant search',
			[],
			$sequence_id
		);

		$embedding_response = $this->get_embeddings_via_selected_provider( [ $question ], $sequence_id );
		if ( is_wp_error( $embedding_response ) || empty( $embedding_response['data'][0]['embedding'] ) ) {
			\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
				'ERROR',
				'Embedding generation failed for Qdrant search',
				[
					'error' => is_wp_error( $embedding_response ) ? $embedding_response->get_error_message() : 'No embedding data',
				],
				$sequence_id
			);
			return new WP_Error( 'qdrant_error', 'Embedding generation failed.' );
		}

		$vector = $embedding_response['data'][0]['embedding'];

		// Log embedding generation completion
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
			'INFO',
			'Embedding generation completed, starting Qdrant search',
			[
				'vector_dimension' => count( $vector ),
			],
			$sequence_id
		);

		$default_limit = 50;
		$settings      = get_option( 'fe_search_ai_settings', [] );
		$rerank        = isset( $settings['rerank'] ) && is_array( $settings['rerank'] ) ? $settings['rerank'] : [];
		if ( isset( $rerank['initial_k'] ) ) {
			$default_limit = (int) $rerank['initial_k'];
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$limit = (int) apply_filters( 'fe_search_ai_qdrant_search_limit', $default_limit, $question );
		if ( $limit <= 0 ) {
			$limit = $default_limit;
		}

		$body = [
			'vector'       => $vector,
			'limit'        => $limit,
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'Qdrant search request failed.',
				[
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				]
			);
			return new WP_Error( 'qdrant_error', $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$body_text = wp_remote_retrieve_body( $response );
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'Qdrant search returned non-2xx status.',
				[
					'http_status'   => $status_code,
					'response_body' => $body_text,
				]
			);
			return new WP_Error( 'qdrant_error', 'Qdrant returned non-2xx status.' );
		}

		$body_text = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body_text, true );
		if ( ! is_array( $data ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
			return new WP_Error( 'qdrant_error', 'Qdrant response is invalid.' );
		}

		$results = [];
		foreach ( $data['result'] as $point ) {
			if ( empty( $point['payload'] ) || ! is_array( $point['payload'] ) ) {
				continue;
			}
			$payload         = $point['payload'];
			$content_snippet = isset( $payload['content_snippet'] ) ? (string) $payload['content_snippet'] : '';
			$permalink       = isset( $payload['permalink'] ) ? (string) $payload['permalink'] : '';
			$post_id         = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
			$title           = isset( $payload['title'] ) ? (string) $payload['title'] : '';

			if ( '' === $content_snippet || '' === $permalink ) {
				continue;
			}

			// If we don't have post_id or title from payload, try to extract them
			if ( $post_id === 0 ) {
				$url_parts = wp_parse_url( $permalink );
				if ( isset( $url_parts['path'] ) ) {
					$path_parts = explode( '/', trim( $url_parts['path'], '/' ) );
					$last_part  = end( $path_parts );
					if ( is_numeric( $last_part ) ) {
						$post_id = (int) $last_part;
					}
				}
			}

			if ( empty( $title ) && $post_id > 0 ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$title = $post->post_title;
				}
			}

			$results[] = [
				'content_chunk' => $content_snippet,
				'permalink'     => $permalink,
				'post_id'       => $post_id,
				'title'         => $title ?: 'Untitled',
				'source'        => 'qdrant',
				'score'         => isset( $point['score'] ) ? (float) $point['score'] : 0.0,
			];
		}

		// Log Qdrant search completion
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
			'INFO',
			'Qdrant search completed',
			[
				'hits'              => count( $results ),
				'sample_permalinks' => array_slice(
					array_column( $results, 'permalink' ),
					0,
					3
				),
			],
			$sequence_id
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
		// Get sequence ID from logger if available (passed via global or static context)
		$sequence_id = '';
		if ( isset( $GLOBALS['fe_search_ai_current_sequence_id'] ) ) {
			$sequence_id = $GLOBALS['fe_search_ai_current_sequence_id'];
		}

		// When Qdrant is enabled, prefer vector search via Qdrant.
		$settings      = get_option( 'fe_search_ai_settings', [] );
		$vector_config = isset( $settings['vector'] ) && is_array( $settings['vector'] ) ? $settings['vector'] : [];
		$qdrant_config = isset( $vector_config['qdrant'] ) && is_array( $vector_config['qdrant'] ) ? $vector_config['qdrant'] : [];
		$vector_store  = $vector_config['store'] ?? 'mariadb';
		$stores        = isset( $vector_config['stores'] ) && is_array( $vector_config['stores'] ) ? $vector_config['stores'] : [];
		$qdrant_store  = isset( $stores['qdrant'] ) ? ! empty( $stores['qdrant'] ) : ( 'qdrant' === $vector_store );

		$qdrant_enabled = (
			$qdrant_store &&
			! empty( $qdrant_config['endpoint'] ) &&
			! empty( $qdrant_config['api_key'] ) &&
			! empty( $qdrant_config['collection'] )
		);

		$hybrid_search = ! empty( $vector_config['hybrid_search'] );
		if ( $hybrid_search && $qdrant_enabled ) {
			$qdrant_results  = $this->find_similar_chunks_via_qdrant( $question, $qdrant_config );
			$keyword_results = $this->find_similar_chunks_via_keyword_index( $question, $sequence_id );
			if ( ! is_wp_error( $qdrant_results ) ) {
				return $this->merge_hybrid_search_results( $qdrant_results, $keyword_results, $question, $sequence_id );
			}
		}

		if ( $qdrant_enabled ) {
			$results = $this->find_similar_chunks_via_qdrant( $question, $qdrant_config );
			if ( ! is_wp_error( $results ) ) {
				return $results;
			}
		}

		return $this->find_similar_chunks_via_keyword_index( $question, $sequence_id );
	}

	/**
	 * Retrieves similar chunks from the local keyword index.
	 *
	 * @param string $question    The end user's question text.
	 * @param string $sequence_id Optional log sequence ID.
	 * @return array An array of the most relevant text chunks and their permalinks.
	 */
	private function find_similar_chunks_via_keyword_index( $question, $sequence_id = '' ) {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_search_ai_vectors';
		$index_table   = $wpdb->prefix . 'fe_search_ai_keyword_index';

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
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
			'DEBUG',
			'Keyword extraction from user question.',
			[
				'initial_keywords' => count( $keywords ),
				'valid_keywords'   => count( $valid_keywords ),
				'keywords_hash'    => md5( wp_json_encode( array_values( $valid_keywords ) ) ),
			],
			$sequence_id
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
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$max_chunks = (int) apply_filters( 'fe_search_ai_max_chunks_for_llm', 100, $question );
		if ( $max_chunks <= 0 ) {
			$max_chunks = 100;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $valid_keywords ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore PluginCheck.Security.PreparedSQLInterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
		// phpcs:ignore PluginCheck.Security.NoCaching
		// Table name is interpolated but controlled internally, keywords are prepared.
		$sql        = "SELECT DISTINCT `vector_id` FROM `{$index_table}` WHERE `keyword` IN ( {$placeholders} ) LIMIT {$max_chunks}";
		$vector_ids = $wpdb->get_col( $wpdb->prepare( $sql, $valid_keywords ) );

		// DEBUG: Log index search results.
		\FESearchAI\Core\FE_Search_AI_Logger::log(
			'DEBUG',
			'Keyword index search completed.',
			[
				'keywords_searched_count' => count( $valid_keywords ),
				'keywords_hash'           => md5( wp_json_encode( array_values( $valid_keywords ) ) ),
				'matched_vector_ids'      => count( $vector_ids ),
			]
		);

		if ( empty( $vector_ids ) ) {
			return [];
		}

		// Retrieve the full content chunks for the found vector IDs.
		// Order by newest post date to keep UX stable when reranking is disabled.
		$placeholders = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore PluginCheck.Security.PreparedSQLInterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
		// phpcs:ignore PluginCheck.Security.NoCaching
		// Table names are interpolated but controlled internally, vector_ids are prepared.
		$sql         = "SELECT v.`content_chunk`, v.`summary_text`, v.`post_id` FROM `{$vectors_table}` v INNER JOIN `{$wpdb->posts}` p ON p.ID = v.post_id WHERE v.`id` IN ( {$placeholders} ) ORDER BY p.post_date DESC";
		$chunks_data = $wpdb->get_results( $wpdb->prepare( $sql, $vector_ids ), ARRAY_A );

		// Add permalink and additional data to the results.
		$results = [];
		foreach ( $chunks_data as $row ) {
			$post      = get_post( $row['post_id'] );
			$results[] = [
				'content_chunk' => $row['content_chunk'],
				'summary_text'  => isset( $row['summary_text'] ) ? (string) $row['summary_text'] : '',
				'permalink'     => get_permalink( $row['post_id'] ),
				'post_id'       => $row['post_id'],
				'title'         => $post ? $post->post_title : 'Untitled',
				'source'        => 'keyword',
			];
		}

		return $results;
	}

	/**
	 * Merges vector and keyword search results using Reciprocal Rank Fusion.
	 *
	 * @param array  $qdrant_results  Results returned from Qdrant.
	 * @param array  $keyword_results Results returned from the keyword index.
	 * @param string $question        The end user's question text.
	 * @param string $sequence_id     Optional log sequence ID.
	 * @return array Merged and ranked search results.
	 */
	private function merge_hybrid_search_results( $qdrant_results, $keyword_results, $question, $sequence_id = '' ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$rrf_k = (int) apply_filters( 'fe_search_ai_hybrid_rrf_k', 60, $question );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$max_results = (int) apply_filters( 'fe_search_ai_hybrid_search_limit', 100, $question );
		if ( $rrf_k <= 0 ) {
			$rrf_k = 60;
		}
		if ( $max_results <= 0 ) {
			$max_results = 100;
		}

		$merged = [];
		$lists  = [
			'qdrant'  => is_array( $qdrant_results ) ? $qdrant_results : [],
			'keyword' => is_array( $keyword_results ) ? $keyword_results : [],
		];

		foreach ( $lists as $source => $results ) {
			foreach ( array_values( $results ) as $rank => $chunk ) {
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				$key = $this->get_hybrid_search_result_key( $chunk );
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $merged[ $key ] ) ) {
					$merged[ $key ]                    = $chunk;
					$merged[ $key ]['hybrid_score']    = 0.0;
					$merged[ $key ]['hybrid_sources']  = [];
					$merged[ $key ]['hybrid_rankings'] = [];
				}
				$merged[ $key ]['hybrid_score']              += 1 / ( $rrf_k + $rank + 1 );
				$merged[ $key ]['hybrid_sources'][ $source ]  = true;
				$merged[ $key ]['hybrid_rankings'][ $source ] = $rank + 1;
			}
		}

		$results = array_values( $merged );
		usort(
			$results,
			function ( $a, $b ) {
				return ( $b['hybrid_score'] ?? 0 ) <=> ( $a['hybrid_score'] ?? 0 );
			}
		);
		$results = array_slice( $results, 0, $max_results );

		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
			'INFO',
			'Hybrid search completed.',
			[
				'qdrant_hits'  => is_array( $qdrant_results ) ? count( $qdrant_results ) : 0,
				'keyword_hits' => is_array( $keyword_results ) ? count( $keyword_results ) : 0,
				'merged_hits'  => count( $results ),
				'rrf_k'        => $rrf_k,
				'max_results'  => $max_results,
			],
			$sequence_id
		);

		return $results;
	}

	/**
	 * Builds a stable deduplication key for hybrid search results.
	 *
	 * @param array $chunk Search result chunk.
	 * @return string Deduplication key.
	 */
	private function get_hybrid_search_result_key( $chunk ) {
		if ( ! is_array( $chunk ) ) {
			return '';
		}
		$post_id = isset( $chunk['post_id'] ) ? (int) $chunk['post_id'] : 0;
		$url     = isset( $chunk['permalink'] ) ? (string) $chunk['permalink'] : '';
		$content = isset( $chunk['content_chunk'] ) ? (string) $chunk['content_chunk'] : '';
		return md5( $post_id . '|' . $url . '|' . $content );
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

		$pt_options['include_title']   = (bool) ( $pt_options['snippet_include_title'] ?? $pt_options['include_title'] ?? false );
		$pt_options['include_content'] = (bool) ( $pt_options['snippet_include_content'] ?? $pt_options['include_content'] ?? false );
		$pt_options['include_date']    = (bool) ( $pt_options['snippet_include_date'] ?? $pt_options['include_date'] ?? false );
		$pt_options['include_author']  = (bool) ( $pt_options['snippet_include_author'] ?? $pt_options['include_author'] ?? false );

		// If there is no explicit config for this post type yet (e.g. after schema change),
		// fall back to sensible defaults so that content is actually indexed.
		if ( empty( $pt_options ) ) {
			$pt_options = [
				'include_title'      => true,
				'include_content'    => true,
				'include_date'       => true,
				'include_author'     => true,
				'snippet_taxonomies' => [],
			];
		}

		$meta_parts = [];
		$body_text  = '';

		// Build metadata in compact format
		$meta_parts[] = sprintf( 'ID: %d', $post->ID );
		if ( ! empty( $pt_options['include_title'] ) ) {
			$meta_parts[] = sprintf( 'Title: %s', $post->post_title );
		}
		$meta_parts[] = sprintf( 'URL: %s', get_permalink( $post ) );

		if ( ! empty( $pt_options['include_date'] ) ) {
			$meta_parts[] = sprintf( 'Date: %s', gmdate( 'Y-m-d', strtotime( $post->post_date ) ) );
		}
		if ( ! empty( $pt_options['include_author'] ) ) {
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
					$meta_parts[] = sprintf( 'Author: %s', $nickname );
				}
			}
		}

		if ( ! empty( $pt_options['snippet_taxonomies'] ) ) {
			$metadata_items = [];
			foreach ( $pt_options['snippet_taxonomies'] as $tax_slug => $tax_config ) {
				if ( empty( $tax_config['enabled'] ) ) {
					continue;
				}

				$terms = get_the_terms( $post->ID, $tax_slug );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}

				$taxonomy = get_taxonomy( $tax_slug );
				if ( ! $taxonomy ) {
					continue;
				}

				// Filter terms based on behavior and term_ids
				$behavior = $tax_config['behavior'] ?? 'include_terms';
				$term_ids = ! empty( $tax_config['term_ids'] ) ? array_map( 'intval', explode( ',', $tax_config['term_ids'] ) ) : [];

				if ( 'include_terms' === $behavior && ! empty( $term_ids ) ) {
					$terms = array_filter(
						$terms,
						function ( $term ) use ( $term_ids ) {
							return in_array( $term->term_id, $term_ids, true );
						}
					);
				} elseif ( 'exclude_terms' === $behavior && ! empty( $term_ids ) ) {
					$terms = array_filter(
						$terms,
						function ( $term ) use ( $term_ids ) {
							return ! in_array( $term->term_id, $term_ids, true );
						}
					);
				}

				if ( empty( $terms ) ) {
					continue;
				}

				$label      = $taxonomy->label;
				$term_names = wp_list_pluck( $terms, 'name' );
				foreach ( $term_names as $term_name ) {
					$metadata_items[] = sprintf( '[%s:%s]', $label, $term_name );
				}
			}
			if ( ! empty( $metadata_items ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				// Hook name is properly prefixed with fe_search_ai_.
				$metadata_items = apply_filters( 'fe_search_ai_taxonomy_items', $metadata_items, $post, $pt_options );
				$meta_parts[]   = 'Metadata: ' . implode( '', $metadata_items );
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$meta_parts = apply_filters( 'fe_search_ai_post_metadata_parts', $meta_parts, $post, $pt_options );

		// Add the main content.
		if ( ! empty( $pt_options['include_content'] ) ) {
			$content   = strip_shortcodes( $post->post_content );
			$content   = wp_strip_all_tags( $content );
			$body_text = $content;
		}

		if ( empty( $meta_parts ) && '' === trim( (string) $body_text ) ) {
			return [];
		}

		// Join metadata into a single block of text.
		$meta_text = implode( "\n", $meta_parts );

		/**
		 * A filter hook for customizing the chunk size used when splitting text.
		 * Hook name: fe_search_ai_chunk_size
		 *
		 * The chunk size controls how much context each piece carries when
		 * creating text chunks for embeddings and keyword indexing.
		 *
		 * @param int      $chunk_size Default chunk size in characters.
		 * @param \WP_Post $post       The post being processed.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$chunk_size = (int) apply_filters( 'fe_search_ai_chunk_size', 1000, $post );
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
					$chunks[] = trim( $meta_text . "\n\nContent:\n" . $body_text );
				} else {
					$chunks[] = "Content:\n" . trim( $body_text );
				}
			} else {
				// Split the body into sentence-based chunks, each limited by available_for_body.
				$sentences    = preg_split( '/(?<=[。．！？\n])\s*/u', $body_text, -1, PREG_SPLIT_NO_EMPTY );
				$current_body = '';
				foreach ( $sentences as $sentence ) {
					if ( mb_strlen( $current_body . $sentence ) > $available_for_body ) {
						// Flush the current body chunk.
						if ( '' !== $meta_text ) {
							$chunks[] = trim( $meta_text . "\n\nContent:\n" . $current_body );
						} else {
							$chunks[] = "Content:\n" . trim( $current_body );
						}
						$current_body = $sentence;
					} else {
						$current_body .= $sentence;
					}
				}
				if ( '' !== $current_body ) {
					if ( '' !== $meta_text ) {
						$chunks[] = trim( $meta_text . "\n\nContent:\n" . $current_body );
					} else {
						$chunks[] = "Content:\n" . trim( $current_body );
					}
				}
			}
		}

		$permalink        = get_permalink( $post );
		$chunks_with_meta = [];
		foreach ( $chunks as $chunk_content ) {
			// Add the chunk content and its metadata to the list.
			$chunks_with_meta[] = [
				'content_chunk' => $chunk_content,
				'permalink'     => $permalink,
				'post_id'       => $post->ID,
				'title'         => $post->post_title,
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
	public function get_embeddings_via_selected_provider( $texts, $sequence_id = '' ) {

		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
			'INFO',
			'Embedding process started.',
			[
				'provider'     => $this->options['provider']['embedding'] ?? 'openai',
				'chunks_count' => count( $texts ),
			],
			$sequence_id
		);

		$provider = $this->options['provider']['embedding'] ?? 'openai';

		/**
		 * A filter hook for interposing your own embedding provider processing.
		 * Hook name: fe_search_ai_embedding_result_for_{provider slug}
		 *
		 * @param WP_Error|array|null $result Result. If null, the default processing will be performed.
		 * @param array               $texts  Array of text to vectorize.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$result = apply_filters( "fe_search_ai_embedding_result_for_{$provider}", null, $texts );

		if ( null !== $result ) {
			return $result;
		}

		switch ( $provider ) {
			case 'google':
				return $this->fetch_embeddings_for_gemini( $texts, $sequence_id );
			case 'openai':
			default:
				return $this->fetch_embeddings_for_openai( $texts, $sequence_id );
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
	public function fetch_embeddings_for_openai( $texts, $sequence_id = '' ) {
		// Start the timer.
		$start_time = microtime( true );

		$encrypted_key = $this->options['provider']['openai_key'] ?? '';
		$api_key       = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( empty( $api_key ) ) {
			// Log the error before returning.
			\FESearchAI\Core\FE_Search_AI_Logger::log( 'ERROR', 'OpenAI Embedding API call skipped: API Key is not set.' );
			return new \WP_Error( 'api_key_missing', __( 'The OpenAI API key is not configured.', 'fe-search-ai' ) );
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
				: __( 'Unknown API Error', 'fe-search-ai' );

			// Log the API error.
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'OpenAI Embedding API call failed (API Error).',
				[
					'http_status'   => $response_code,
					'error_message' => $error_message,
					'duration_ms'   => $duration,
				]
			);

			return new WP_Error( 'api_error', __( 'API Error', 'fe-search-ai' ) . ': ' . $error_message );
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
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence(
			'INFO',
			'OpenAI Embedding API call succeeded.',
			[
				'http_status'     => wp_remote_retrieve_response_code( $response ),
				'duration_ms'     => $duration,
				'total_tokens'    => $usage['total_tokens'] ?? 'N/A',
				'embedding_model' => $embedding_model,
				'embedding_dim'   => $embedding_dim,
			],
			$sequence_id
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
		$encrypted_key = $this->options['provider']['google_key'] ?? '';
		$api_key       = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( empty( $api_key ) ) {
			// Log the error before returning.
			\FESearchAI\Core\FE_Search_AI_Logger::log( 'ERROR', 'Gemini Embedding API call skipped: API Key is not set.' );
			return new \WP_Error( 'api_key_missing', __( 'The Google Cloud API key is not configured.', 'fe-search-ai' ) );
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
				: __( 'Unknown API Error', 'fe-search-ai' );

			// Log the API error.
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'Gemini Embedding API call failed (API Error).',
				[
					'http_status'   => $response_code,
					'error_message' => $error_message,
					'duration_ms'   => $duration,
				]
			);

			return new WP_Error( 'api_error', __( 'API Error', 'fe-search-ai' ) . ': ' . $error_message );
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
		\FESearchAI\Core\FE_Search_AI_Logger::log(
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
		$text_normalized = mb_convert_kana( $text_normalized, 'as', 'UTF-8' );

		// Remove all symbols (including asterisk, which was causing issues)
		$text_normalized = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text_normalized );

		$locale     = get_locale();
		$lang_code  = strstr( $locale, '_', true ) ?: $locale;
		$stop_words = [];
		// Load stop words
		// Use the correct plugin directory constant for locating i18n files.
		$lang_file = FE_SEARCH_AI_PLUGIN_DIR . "includes/i18n/{$locale}.php";
		if ( ! file_exists( $lang_file ) ) {
			$lang_file = FE_SEARCH_AI_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
		}
		if ( file_exists( $lang_file ) ) {
			$lang_data  = include $lang_file;
			$stop_words = $lang_data['stop_words'] ?? [];
		}

		/**
		 * A filter hook for customizing the stop-word list used during tokenization.
		 * Hook name: fe_search_ai_stop_words
		 *
		 * This allows site owners and developers to add or remove language-specific
		 * stop words before keyword extraction.
		 *
		 * @param array  $stop_words The default stop-word list loaded for the locale.
		 * @param string $locale     The full locale string (e.g. "en_US", "ja").
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$stop_words = apply_filters( 'fe_search_ai_stop_words', $stop_words, $locale );

		// Tokenize
		if ( 'ja' === $lang_code ) {
			$tokenizer_engine = $this->get_japanese_tokenizer_engine();

			// Force Yahoo! MA API if PHP < 8.0 (TinySegmenter requires PHP >= 8.0).
			if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
				$tokenizer_engine = 'yahoo_ma';
			}

			if ( 'yahoo_ma' === $tokenizer_engine && $this->get_yahoo_app_id() ) {
				$words = $this->tokenize_with_yahoo_api( $text_normalized );
				if ( is_wp_error( $words ) || empty( $words ) ) {
					// Fallback to TinySegmenter if available (PHP >= 8.0).
					if ( null !== $this->segmenter ) {
						$words = $this->segmenter->segment( $text_normalized );
					} else {
						// No tokenizer available, fall back to simple split.
						$words = preg_split( '/\s+/', $text_normalized, -1, PREG_SPLIT_NO_EMPTY );
					}
				}
			} else {
				// Use TinySegmenter if available (PHP >= 8.0).
				if ( null !== $this->segmenter ) {
					$words = $this->segmenter->segment( $text_normalized );
				} else {
					// TinySegmenter not available, fall back to simple split.
					$words = preg_split( '/\s+/', $text_normalized, -1, PREG_SPLIT_NO_EMPTY );
				}
			}
		} else {
			$words = preg_split( '/\s+/', $text_normalized, -1, PREG_SPLIT_NO_EMPTY );
		}

		/**
		 * A filter hook for customizing raw tokens per language before stop-word
		 * removal and stemming.
		 * Hook name: fe_search_ai_tokens_for_lang
		 *
		 * This allows developers to plug in their own tokenization logic for
		 * languages that do not use whitespace-separated words (e.g. Chinese,
		 * Korean), or to otherwise post-process the token list.
		 *
		 * @param array  $words           Raw tokens produced by the built-in tokenizer.
		 * @param string $text_normalized The normalized input text.
		 * @param string $lang_code       Two-letter language code (e.g. 'en', 'ja', 'zh', 'ko').
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$words = apply_filters( 'fe_search_ai_tokens_for_lang', $words, $text_normalized, $lang_code );

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
		 * Hook name: fe_search_ai_tokenize_text
		 *
		 * This allows site owners and developers to post-process or augment the
		 * extracted keywords (for example, adding custom synonyms or removing
		 * domain-specific terms) before they are used for indexing or search.
		 *
		 * @param array  $final_words     The final list of keywords.
		 * @param string $text_normalized The normalized input text.
		 * @param string $locale          The full locale string (e.g. "en_US", "ja").
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$final_words = apply_filters( 'fe_search_ai_tokenize_text', $final_words, $text_normalized, $locale );

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
		$encrypted_yahoo_id = $ja_conf['yahoo_id'] ?? '';
		if ( empty( $encrypted_yahoo_id ) ) {
			return '';
		}
		$yahoo_id = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted_yahoo_id );
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'Yahoo MA API returned unexpected response.',
				[
					'http_status' => $code,
				]
			);
			return [];
		}

		if ( $debug_mode ) {
			$tokens_sample = [];
			$max_sample    = 20;
			foreach ( $data['result']['tokens'] as $i => $token_row ) {
				if ( $i >= $max_sample ) {
					break;
				}
				$tokens_sample[] = [
					'i'        => $i,
					'surface'  => (string) ( $token_row[0] ?? '' ),
					'baseform' => (string) ( $token_row[2] ?? '' ),
				];
			}
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'INFO',
				'Yahoo MA API tokens sample.',
				[
					'http_status' => $code,
					'sample'      => $tokens_sample,
				]
			);
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'INFO',
				'Yahoo MA API call succeeded.',
				[
					'http_status'  => $code,
					'token_count'  => count( $words ),
					'words_sample' => array_slice( $words, 0, 30 ),
				]
			);
		}

		return $words;
	}

	/**
	 * Renders an administrator notification about internationalization
	 */
	public function render_i18n_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// This is a display method, not a form handler. No nonce verification needed.
		// Do not show the notice if it was recently dismissed.
		if ( get_transient( 'fe_search_ai_i18n_notice_dismissed' ) ) {
			return;
		}

		// Only show the notice when there is no bundled i18n file
		// for the current locale or its language code.
		$locale    = get_locale();
		$lang_code = strstr( $locale, '_', true ) ?: $locale;
		$lang_file = FE_SEARCH_AI_PLUGIN_DIR . "includes/i18n/{$locale}.php";
		if ( ! file_exists( $lang_file ) ) {
			$lang_file = FE_SEARCH_AI_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
		}
		if ( file_exists( $lang_file ) ) {
			return;
		}

		// What happens when a user clicks on a "hidden" link?
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// This is a dismiss action, not a form submission.
		if ( isset( $_GET['fe-search-ai-dismiss-i18n-notice'] ) ) {
			// Flag to hide this notification for one week
			set_transient( 'fe_search_ai_i18n_notice_dismissed', true, WEEK_IN_SECONDS );
			return;
		}
		?>
		<div class="notice notice-info is-dismissible" data-dismiss-url="<?php echo esc_url( add_query_arg( 'fe-search-ai-dismiss-i18n-notice', '1' ) ); ?>">
			<p>
				<b>FE Search AI:</b> <?php esc_html_e( 'Your site language is not fully optimized for keyword search. We welcome contributions for new languages!', 'fe-search-ai' ); ?>
				<a href="https://github.com/firstelementjp/fe-search-ai" target="_blank" style="margin-left: 10px;"><?php esc_html_e( 'Contribute on GitHub', 'fe-search-ai' ); ?></a>
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
						var formData = new (window['FormData'])();
						formData.append('action', 'dismiss-wp-pointer');
						formData.append('pointer', 'fe_search_ai_i18n_notice_dismissed');
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
	 * This method hooks into the 'fe_search_ai_tokenizer_status' filter.
	 *
	 * @since 0.9.0
	 * @param array $statuses The existing status messages.
	 * @return array The modified status messages.
	 */
	public function add_japanese_tokenizer_status( $statuses ) {
		if ( 'ja' !== get_locale() ) {
			return $statuses;
		}

		$status_label = '<strong>' . esc_html__( 'Japanese Tokenizer Status', 'fe-search-ai' ) . ':</strong>';
		$engine       = $this->get_japanese_tokenizer_engine();
		$yahoo_id     = $this->get_yahoo_app_id();
		if ( 'yahoo_ma' === $engine && ! empty( $yahoo_id ) ) {
			$this->japanese_tokenizer = 'yahoo_ma';
			$status_text              = '<span style="color:#46b450;">' . esc_html__( 'Yahoo! Japanese MA API (App ID configured)', 'fe-search-ai' ) . '</span>';
		} elseif ( 'yahoo_ma' === $engine ) {
			$this->japanese_tokenizer = 'yahoo_ma';
			$status_text              = '<span style="color:#dc3232;">' . esc_html__( 'Yahoo! Japanese MA API (App ID missing)', 'fe-search-ai' ) . '</span>';
		} else {
			$this->japanese_tokenizer = 'tinysegmenter';
			$status_text              = '<span style="color:#a0a5aa;">' . esc_html__( 'Built-in (TinySegmenter)', 'fe-search-ai' ) . '</span>';
		}

		/**
		 * Filter the Japanese tokenizer status HTML shown in the Sync page.
		 *
		 * This allows developers to replace the default MeCab/TinySegmenter
		 * label with their own description (for example, when providing a
		 * custom tokenizer implementation via other hooks).
		 *
		 * @since 0.9.0
		 *
		 * @param string $status_text        The HTML snippet describing the tokenizer.
		 * @param string $japanese_tokenizer The current tokenizer identifier (e.g. 'tinysegmenter', 'mecab').
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// Hook name is properly prefixed with fe_search_ai_.
		$status_text = apply_filters( 'fe_search_ai_japanese_tokenizer_status_text', $status_text, $this->japanese_tokenizer );

		$statuses[] = $status_label . ' ' . $status_text;
		return $statuses;
	}
}
