<?php
/**
 * Handles real-time synchronization of posts with the AI index.
 *
 * This file defines the FEAS_AI_Sync_Hooks class, which is responsible for
 * hooking into WordPress actions like 'save_post' and 'delete_post' to
 * automatically keep the vector database up-to-date.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.0.0
 */

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Manages WordPress hooks for real-time post synchronization.
 *
 * This class listens for post creation, updates, and deletions, and triggers
 * the appropriate indexing or de-indexing actions by using helper methods
 * from the main Sync_Handler class.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Sync_Hooks {

	/**
	 * A reference to the main sync handler class.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var \FEAISearch\Ajax\FEAS_AI_Sync_Handler $sync_handler The main sync handler instance.
	 */
	private $sync_handler;

	/**
	 * Constructor.
	 *
	 * Stores the sync handler dependency and registers the necessary WordPress hooks.
	 *
	 * @since 1.0.0
	 * @param \FEAISearch\Ajax\FEAS_AI_Sync_Handler $sync_handler The main sync handler instance.
	 */
	public function __construct( $sync_handler ) {
		$this->sync_handler = $sync_handler;

		add_action( 'save_post', [ $this, 'sync_single_post_on_update' ], 10, 2 );
		add_action( 'wp_trash_post', [ $this, 'delete_post_from_index' ] );
		add_action( 'delete_post', [ $this, 'delete_post_from_index' ] );
	}

	/**
	 * Syncs a single post when it is saved or updated.
	 *
	 * This method is hooked to 'save_post' and handles the creation or update
	 * of vector embeddings and keyword indexes for a specific post.
	 *
	 * @since 1.0.0
	 * @param int     $post_id The ID of the post being saved.
	 * @param \WP_Post $post    The post object.
	 */
	public function sync_single_post_on_update( $post_id, $post ) {
		$sync_options = get_option( 'feas_ai_sync_options', [] );
		$pt_options   = $sync_options['post_types'][ $post->post_type ] ?? [];

		// Guard clauses: exit if the post type is not enabled for sync, if it's not a published post, or if it's an autosave/revision.
		if ( empty( $pt_options['enabled'] ) || 'publish' !== $post->post_status || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// First, remove any existing index data for this post to prevent duplicates.
		$this->delete_post_from_index( $post_id );

		// Create text chunks using the helper method from the sync handler.
		$chunks_with_meta = $this->sync_handler->create_chunks_from_post( $post );
		if ( empty( $chunks_with_meta ) ) {
			return;
		}

		// Get vector embeddings for the chunks using the helper method.
		$embedding_response = $this->sync_handler->get_embeddings_via_selected_provider( $chunks_with_meta );

		if ( ! is_wp_error( $embedding_response ) && ! empty( $embedding_response['data'] ) ) {
			global $wpdb;
			$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
			$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

			$vectors_data = $embedding_response['data'];
			foreach ( $vectors_data as $index => $vector_item ) {
				if ( empty( $vector_item['embedding'] ) ) {
					continue;
				}

				// Get the language of the post for multilingual support (e.g., with Polylang).
				$lang_code = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : get_locale();

				// Insert the vector data into the custom database table.
				$wpdb->insert(
					$vectors_table,
					[
						'post_id'       => $post->ID,
						'lang'          => $lang_code,
						'chunk_index'   => $index,
						'content_chunk' => $chunks_with_meta[ $index ],
						'vector_data'   => wp_json_encode( $vector_item['embedding'] ),
						'created_at'    => current_time( 'mysql' ),
					]
				);

				// If the vector was inserted successfully, create keyword indexes for it.
				$vector_id = $wpdb->insert_id;
				if ( $vector_id ) {
					// Tokenize the text chunk into keywords using the language-aware helper method.
					$keywords = $this->sync_handler->tokenize_text( $chunks_with_meta[ $index ] );

					foreach ( array_unique( $keywords ) as $keyword ) {
						if ( mb_strlen( $keyword ) > 1 ) {
							$wpdb->insert(
								$index_table,
								[
									'keyword'   => $keyword,
									'vector_id' => $vector_id,
									'lang'      => $lang_code, // Also store lang in the index table
								]
							);
						}
					}
				}
			}
		}

		// Update the timestamp of the last successful sync.
		update_option( 'feas_ai_last_sync_timestamp', current_time( 'timestamp' ) );
	}

	/**
	 * Deletes all index data associated with a post.
	 *
	 * This method is hooked to 'wp_trash_post' and 'delete_post' to ensure that
	 * when a post is removed, its data is also removed from the AI index.
	 *
	 * @since 1.0.0
	 * @param int $post_id The ID of the post being deleted.
	 */
	public function delete_post_from_index( $post_id ) {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		// Find all vector IDs associated with the post.
		$vector_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );

		if ( ! empty( $vector_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );

			// Delete all keyword index entries pointing to these vectors.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$index_table}` WHERE `vector_id` IN ( {$placeholders} )", $vector_ids ) );

			// Delete the vectors themselves.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );
		}
	}
}
