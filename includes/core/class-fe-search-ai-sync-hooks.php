<?php
/**
 * Handles real-time synchronization of posts with the AI index.
 *
 * This file defines the FE_AI_Search_Sync_Hooks class, which is responsible for
 * hooking into WordPress actions like 'save_post' and 'delete_post' to
 * automatically keep the vector database up-to-date.
 *
 * @package    fe-search-ai
 * @subpackage Core
 * @since      0.9.0
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WordPress hooks for real-time post synchronization.
 *
 * This class listens for post creation, updates, and deletions, and triggers
 * the appropriate indexing or de-indexing actions by using helper methods
 * from the main Sync_Handler class.
 *
 * Indexing work is queued and processed in the background via WP-Cron so that
 * saving a post is not slowed down by embedding or summary API calls. Set the
 * 'fe_search_ai_realtime_sync_background' filter to false to restore
 * synchronous indexing inside the save request.
 *
 * @since      0.9.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Sync_Hooks {

	/**
	 * Option name that stores the pending real-time sync queue.
	 *
	 * The queue is an associative array of post IDs mapped to enqueue
	 * timestamps. It is never autoloaded.
	 *
	 * @since 1.3.0
	 * @var   string
	 */
	const QUEUE_OPTION = 'fe_search_ai_sync_queue';

	/**
	 * Option name used as a processing lock for the sync queue.
	 *
	 * Holds the timestamp at which the current queue run started. Stale locks
	 * are stolen after ten minutes.
	 *
	 * @since 1.3.0
	 * @var   string
	 */
	const LOCK_OPTION = 'fe_search_ai_sync_queue_lock';

	/**
	 * WP-Cron hook name used to trigger background queue processing.
	 *
	 * @since 1.3.0
	 * @var   string
	 */
	const CRON_HOOK = 'fe_search_ai_process_sync_queue';

	/**
	 * Seconds after which a queue lock is considered stale and may be stolen.
	 *
	 * @since 1.3.0
	 * @var   int
	 */
	const LOCK_TTL = 600;

	/**
	 * Holds the master array of all plugin settings.
	 *
	 * This property is populated in the constructor by fetching the
	 * 'fe_search_ai_settings' option from the database. This allows
	 * all methods in this class to access settings without repeated
	 * database calls, improving performance.
	 *
	 * @since 0.9.0
	 * @access private
	 * @var    array $options The complete settings array.
	 */
	private $options = [];

	/**
	 * A reference to the main sync handler class.
	 *
	 * @since 0.9.0
	 * @access private
	 * @var    \FESearchAI\Ajax\FE_Search_AI_Sync_Handler $sync_handler The main sync handler instance.
	 */
	private $sync_handler;

	/**
	 * Constructor.
	 *
	 * Stores the sync handler dependency and registers the necessary WordPress hooks.
	 *
	 * @since 0.9.0
	 * @param  \FESearchAI\Ajax\FE_Search_AI_Sync_Handler $sync_handler The main sync handler instance.
	 */
	public function __construct( $sync_handler ) {
		$this->options      = get_option( 'fe_search_ai_settings', [] );
		$this->sync_handler = $sync_handler;

		add_action( 'save_post', [ $this, 'sync_single_post_on_update' ], 10, 2 );
		add_action( 'wp_trash_post', [ $this, 'remove_post_from_sync' ] );
		add_action( 'delete_post', [ $this, 'remove_post_from_sync' ] );
		add_action( self::CRON_HOOK, [ $this, 'process_sync_queue' ] );
	}

	/**
	 * Handles the 'save_post' action for real-time synchronization.
	 *
	 * Performs the lightweight eligibility checks, removes stale index rows
	 * immediately, and then either queues the post for background indexing
	 * (default) or indexes it synchronously when the
	 * 'fe_search_ai_realtime_sync_background' filter returns false.
	 *
	 * @since 0.9.0
	 * @param  int      $post_id The ID of the post being saved.
	 * @param  \WP_Post $post    The post object.
	 */
	public function sync_single_post_on_update( $post_id, $post ) {
		$sync_targets = $this->options['sync']['targets'] ?? [];
		$pt_options   = $sync_targets[ $post->post_type ] ?? [];

		if (
			empty( $pt_options['enabled'] ) ||
			'publish' !== $post->post_status ||
			wp_is_post_autosave( $post_id ) ||
			wp_is_post_revision( $post_id )
		) {
			return;
		}

		// Drop existing rows right away so outdated content is never served.
		$this->delete_post_from_index( $post_id );

		/**
		 * Filters whether real-time indexing runs in the background.
		 *
		 * When true (default), the post ID is queued and processed by WP-Cron
		 * in a separate request. When false, embedding generation and index
		 * writes run synchronously inside the save request.
		 *
		 * @since 1.3.0
		 * @param  bool     $background Whether to index in the background.
		 * @param  int      $post_id    The ID of the post being saved.
		 * @param  \WP_Post $post       The post object.
		 */
		// Hook name is properly prefixed with fe_search_ai_.
		if ( apply_filters( 'fe_search_ai_realtime_sync_background', true, $post_id, $post ) ) {
			$this->enqueue_post_for_sync( $post_id );
			return;
		}

		$this->index_post( $post_id, $post );
	}

	/**
	 * Adds a post to the background sync queue and spawns WP-Cron.
	 *
	 * The queue deduplicates post IDs, so saving the same post repeatedly only
	 * results in one pending entry. A new single cron event is scheduled for
	 * each newly queued post; every event drains the whole queue, so surplus
	 * events simply find an empty queue and return.
	 *
	 * @since 1.3.0
	 * @param  int $post_id The ID of the post to queue.
	 * @return void
	 */
	public function enqueue_post_for_sync( $post_id ) {
		$post_id = (int) $post_id;
		$queue   = get_option( self::QUEUE_OPTION, [] );

		if ( ! is_array( $queue ) ) {
			$queue = [];
		}

		if ( isset( $queue[ $post_id ] ) ) {
			// Already queued; a runner is guaranteed by the first enqueue.
			return;
		}

		$queue[ $post_id ] = time();
		$this->save_queue( $queue );

		// A unique argument avoids WP-Cron's duplicate-event rejection window.
		wp_schedule_single_event( time(), self::CRON_HOOK, [ wp_generate_uuid4() ] );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Processes queued posts in the background.
	 *
	 * Hooked to the 'fe_search_ai_process_sync_queue' cron event. A
	 * non-autoloaded option acts as a lock so only one request drains the
	 * queue at a time; stale locks are stolen after ten minutes. When the run
	 * exceeds the time limit, the remaining items are rescheduled.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function process_sync_queue() {
		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $locked_at && ( time() - $locked_at ) < self::LOCK_TTL ) {
			return;
		}

		// add_option() is atomic, so it only succeeds for one concurrent run.
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			// The lock already exists; only steal it when it is stale.
			$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
			if ( $locked_at && ( time() - $locked_at ) < self::LOCK_TTL ) {
				return;
			}
			update_option( self::LOCK_OPTION, time() );
		}

		$start = microtime( true );

		/**
		 * Filters the maximum number of seconds one queue run may take.
		 *
		 * @since 1.3.0
		 * @param  float $seconds Time budget for a single queue run. Default 50.
		 */
		// Hook name is properly prefixed with fe_search_ai_.
		$max_seconds = (float) apply_filters( 'fe_search_ai_sync_queue_time_limit', 50 );

		while ( true ) {
			$queue = get_option( self::QUEUE_OPTION, [] );
			if ( ! is_array( $queue ) || empty( $queue ) ) {
				break;
			}

			$post_id = (int) array_key_first( $queue );
			unset( $queue[ $post_id ] );
			$this->save_queue( $queue );

			$this->index_post( $post_id );

			// Refresh the lock so a legitimate long run is never stolen.
			update_option( self::LOCK_OPTION, time() );

			if ( ( microtime( true ) - $start ) >= $max_seconds ) {
				wp_schedule_single_event( time(), self::CRON_HOOK, [ wp_generate_uuid4() ] );
				if ( function_exists( 'spawn_cron' ) ) {
					spawn_cron();
				}
				break;
			}
		}

		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Removes a post from the sync queue and from the index.
	 *
	 * Hooked to 'wp_trash_post' and 'delete_post'. Deleting from the index is
	 * a fast local operation, so it stays synchronous inside the request.
	 *
	 * @since 1.3.0
	 * @param  int $post_id The ID of the post being removed.
	 * @return void
	 */
	public function remove_post_from_sync( $post_id ) {
		$post_id = (int) $post_id;
		$queue   = get_option( self::QUEUE_OPTION, [] );

		if ( is_array( $queue ) && isset( $queue[ $post_id ] ) ) {
			unset( $queue[ $post_id ] );
			$this->save_queue( $queue );
		}

		$this->delete_post_from_index( $post_id );
	}

	/**
	 * Reindexes a single post.
	 *
	 * Runs the expensive part of real-time synchronization: language
	 * detection, chunking, optional summary generation, embedding API calls,
	 * and index writes. Called by the queue runner in the background, or
	 * directly from 'save_post' when background sync is disabled.
	 *
	 * The post is re-validated here because its status or settings may have
	 * changed between enqueueing and execution.
	 *
	 * @since 1.3.0
	 * @param  int           $post_id The ID of the post to index.
	 * @param  \WP_Post|null $post    Optional post object; loaded when omitted.
	 * @return void
	 */
	public function index_post( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$sync_targets = $this->options['sync']['targets'] ?? [];
		$pt_options   = $sync_targets[ $post->post_type ] ?? [];

		if ( empty( $pt_options['enabled'] ) || 'publish' !== $post->post_status ) {
			return;
		}

		$lang_code = $this->detect_post_language_code( $post_id, $post );

		$this->delete_post_from_index( $post_id );

		// Returns array of chunks with metadata including content and permalink.
		$chunks_with_meta = $this->sync_handler->create_chunks_from_post( $post );
		if ( empty( $chunks_with_meta ) ) {
			return;
		}

		$prepared        = $this->sync_handler->prepare_embedding_texts_from_chunks( $post, $lang_code, $chunks_with_meta );
		$embedding_texts = $prepared['embedding_texts'] ?? [];
		$summaries       = $prepared['summaries'] ?? [];
		$summary_hashes  = $prepared['summary_hashes'] ?? [];
		foreach ( $chunks_with_meta as $i => $chunk_item ) {
			$chunks_with_meta[ $i ]['summary_text'] = isset( $summaries[ $i ] ) ? (string) $summaries[ $i ] : '';
		}
		$embedding_texts    = array_values( $embedding_texts );
		$embedding_response = $this->sync_handler->get_embeddings_via_selected_provider( $embedding_texts );

		if ( ! is_wp_error( $embedding_response ) && ! empty( $embedding_response['data'] ) ) {
			global $wpdb;
			$vectors_table = $wpdb->prefix . 'fe_search_ai_vectors';
			$index_table   = $wpdb->prefix . 'fe_search_ai_keyword_index';

			$vectors_data    = $embedding_response['data'];
			$embedding_model = $embedding_response['embedding_model'] ?? '';
			$embedding_dim   = (int) ( $embedding_response['embedding_dim'] ?? 0 );

			foreach ( $vectors_data as $index => $vector_item ) {
				if ( empty( $vector_item['embedding'] ) ) {
					continue;
				}

				$chunk_content = $chunks_with_meta[ $index ]['content_chunk'] ?? '';
				if ( empty( $chunk_content ) ) {
					continue;
				}
				$summary_text        = isset( $summaries[ $index ] ) ? (string) $summaries[ $index ] : '';
				$summary_hash        = isset( $summary_hashes[ $index ] ) ? (string) $summary_hashes[ $index ] : '';
				$keyword_index_data  = $this->sync_handler->build_keyword_index_data( $chunk_content );
				$keyword_token_count = (int) ( $keyword_index_data['token_count'] ?? 0 );
				$term_frequencies    = isset( $keyword_index_data['term_frequencies'] ) && is_array( $keyword_index_data['term_frequencies'] ) ? $keyword_index_data['term_frequencies'] : [];

				// Insert the vector data into the vectors table.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				// Direct insert required for custom table.
				$wpdb->insert(
					$vectors_table,
					[
						'post_id'             => $post->ID,
						'lang'                => $lang_code,
						'chunk_index'         => $index,
						'content_chunk'       => $chunk_content,
						'summary_text'        => $summary_text,
						'summary_hash'        => $summary_hash,
						'vector_data'         => wp_json_encode( $vector_item['embedding'] ),
						'embedding_model'     => $embedding_model,
						'embedding_dim'       => $embedding_dim,
						'keyword_token_count' => $keyword_token_count,
						'created_at'          => current_time( 'mysql' ),
					]
				);

				$vector_id = $wpdb->insert_id;

				if ( $vector_id ) {
					$this->sync_handler->insert_keyword_index_terms( $index_table, $vector_id, $lang_code, $term_frequencies );
				}
			}

			// Update runtime sync status in the dedicated state option so it is not
			// affected by main settings sanitization.
			$state = get_option( 'fe_search_ai_sync_state', [] );
			if ( ! is_array( $state ) ) {
				$state = [];
			}
			if ( ! isset( $state['status'] ) || ! is_array( $state['status'] ) ) {
				$state['status'] = [];
			}
			// Update last_realtime_sync_timestamp for individual post syncs.
			$state['status']['last_realtime_sync_timestamp'] = time();
			update_option( 'fe_search_ai_sync_state', $state );
		}
	}

	/**
	 * Detects the language code for a post.
	 *
	 * Uses Polylang, WPML, or Bogo when available and falls back to the site
	 * locale. The result is filtered by 'fe_search_ai_post_language_code'.
	 *
	 * @since 1.3.0
	 * @param  int      $post_id The ID of the post being indexed.
	 * @param  \WP_Post $post    The post object.
	 * @return string The detected language code (e.g., 'en', 'ja').
	 */
	private function detect_post_language_code( $post_id, $post ) {
		$lang_code = '';

		if ( function_exists( 'pll_get_post_language' ) ) {
			// Polylang support: Get the language slug (e.g., 'en', 'ja').
			$lang_code = pll_get_post_language( $post_id, 'slug' );
		} elseif ( function_exists( 'wpml_get_language_information' ) ) {
			// WPML support: Get the language code using WPML filter.
			// This is a third-party WPML plugin hook, not our own.
			$lang_details = apply_filters( 'wpml_post_language_details', null, $post_id );
			$lang_code    = $lang_details['language_code'] ?? ''; // e.g., 'en', 'ja'.
		} elseif ( function_exists( 'bogo_get_post_language' ) ) {
			// Bogo stores language in post meta using the locale code.
			$lang_code = get_post_meta( $post_id, '_locale', true );
		}

		if ( empty( $lang_code ) ) {
			$lang_code = get_locale();
		}

		$lang_code_extracted = strstr( $lang_code, '_', true );
		if ( false !== $lang_code_extracted ) {
			$lang_code = $lang_code_extracted;
		}

		/**
		 * Filters the detected language code for a post being indexed.
		 * Allows developers using other multilingual plugins to provide the correct language code.
		 *
		 * @since 0.9.0
		 * @param  string  $lang_code The detected language code (e.g., 'en', 'ja').
		 * @param  int     $post_id   The ID of the post being indexed.
		 * @param  WP_Post $post      The post object.
		 */
		// Hook name is properly prefixed with fe_search_ai_.
		return apply_filters( 'fe_search_ai_post_language_code', $lang_code, $post_id, $post );
	}

	/**
	 * Persists the sync queue option without enabling autoload.
	 *
	 * @since 1.3.0
	 * @param  array $queue Queue keyed by post ID.
	 * @return void
	 */
	private function save_queue( $queue ) {
		if ( ! add_option( self::QUEUE_OPTION, $queue, '', false ) ) {
			update_option( self::QUEUE_OPTION, $queue );
		}
	}

	/**
	 * Deletes all index data associated with a post.
	 *
	 * Called by remove_post_from_sync() on 'wp_trash_post' and 'delete_post',
	 * and before reindexing so that a post's data is replaced atomically.
	 *
	 * @since 0.9.0
	 * @param int $post_id The ID of the post being deleted.
	 */
	public function delete_post_from_index( $post_id ) {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_search_ai_vectors';
		$index_table   = $wpdb->prefix . 'fe_search_ai_keyword_index';

		// Find all vector IDs for the post.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Table name is interpolated but controlled internally, post_id is prepared.
		$vector_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );

		if ( ! empty( $vector_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
			// Delete keyword index entries.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			// Table name is interpolated but controlled internally, placeholders are for IN clause, vector_ids are prepared.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->prepare( "DELETE FROM `{$index_table}` WHERE `vector_id` IN ( {$placeholders} )", $vector_ids )
			);
			// Delete vectors.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			// Table name is interpolated but controlled internally, placeholders are for IN clause, vector_ids are prepared.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->prepare( "DELETE FROM `{$vectors_table}` WHERE `id` IN ( {$placeholders} )", $vector_ids )
			);
		}
	}
}
