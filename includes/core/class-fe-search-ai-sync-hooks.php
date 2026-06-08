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
 * @since      0.9.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Sync_Hooks {

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
		add_action( 'wp_trash_post', [ $this, 'delete_post_from_index' ] );
		add_action( 'delete_post', [ $this, 'delete_post_from_index' ] );
	}

	/**
	 * Syncs a single post when it is saved or updated.
	 *
	 * This method is hooked to 'save_post' and handles the creation or update
	 * of vector embeddings and keyword indexes for a specific post. It includes
	 * logic to detect the post's language using common multilingual plugins.
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

		$lang_code = '';

		if ( function_exists( 'pll_get_post_language' ) ) {
			// Polylang support: Get the language slug (e.g., 'en', 'ja').
			$lang_code = pll_get_post_language( $post_id, 'slug' );
		} elseif ( function_exists( 'wpml_get_language_information' ) ) {
			// WPML support: Get the language code using WPML filter.
			// phpcs:ignore PluginCheck.PluginTheme.NonPrefixedHooknameFound
			// This is a third-party WPML plugin hook, not our own.
			$lang_details = apply_filters( 'wpml_post_language_details', null, $post_id );
			$lang_code    = $lang_details['language_code'] ?? ''; // e.g., 'en', 'ja'
		} elseif ( function_exists( 'bogo_get_post_language' ) ) {
			// Bogo stores language in post meta using the locale code
			$lang_code = get_post_meta( $post_id, '_locale', true );
		}

		if ( empty( $lang_code ) ) {
			$lang_code = get_locale();
		}

		$lang_code = strstr( $lang_code, '_', true ) ?: $lang_code;

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
		$lang_code = apply_filters( 'fe_search_ai_post_language_code', $lang_code, $post_id, $post );

		$this->delete_post_from_index( $post_id );

		// Returns array like: [['content_chunk' => '...', 'permalink' => '...'], ...]
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
				$summary_text = isset( $summaries[ $index ] ) ? (string) $summaries[ $index ] : '';
				$summary_hash = isset( $summary_hashes[ $index ] ) ? (string) $summary_hashes[ $index ] : '';

				// Insert the vector data into the vectors table.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
				// Direct insert required for custom table.
				$wpdb->insert(
					$vectors_table,
					[
						'post_id'         => $post->ID,
						'lang'            => $lang_code,
						'chunk_index'     => $index,
						'content_chunk'   => $chunk_content,
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
					// Tokenize the text chunk into keywords.
					$keywords = $this->sync_handler->tokenize_text( $chunk_content );

					foreach ( $keywords as $keyword ) {
						// Skip very short keywords (often noise).
						if ( mb_strlen( $keyword ) > 1 ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
							// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
							// Direct insert required for custom table.
							$wpdb->insert(
								$index_table,
								[
									'keyword'   => $keyword,
									'vector_id' => $vector_id,
									'lang'      => $lang_code,
								]
							);
						}
					}
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
			$state['status']['last_sync_timestamp'] = current_time( 'timestamp' );
			update_option( 'fe_search_ai_sync_state', $state );
		}
	}

	/**
	 * Deletes all index data associated with a post.
	 *
	 * This method is hooked to 'wp_trash_post' and 'delete_post' to ensure that
	 * when a post is removed, its data is also removed from the AI index.
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
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore PluginCheck.Security.PreparedSQLInterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
		// phpcs:ignore PluginCheck.Security.NoCaching
		// Table name is interpolated but controlled internally, post_id is prepared.
		$vector_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );

		if ( ! empty( $vector_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
			// Delete keyword index entries.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore PluginCheck.Security.PreparedSQLInterpolatedNotPrepared
			// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
			// phpcs:ignore PluginCheck.Security.NoCaching
			// Table name is interpolated but controlled internally, placeholders are for IN clause, vector_ids are prepared.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$index_table}` WHERE `vector_id` IN ( {$placeholders} )", $vector_ids ) );
			// Delete vectors.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore PluginCheck.Security.PreparedSQLInterpolatedNotPrepared
			// phpcs:ignore PluginCheck.Security.DirectDatabaseQuery
			// phpcs:ignore PluginCheck.Security.NoCaching
			// Table name is interpolated but controlled internally, placeholders are for IN clause, vector_ids are prepared.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$vectors_table}` WHERE `id` IN ( {$placeholders} )", $vector_ids ) );
		}
	}
}
