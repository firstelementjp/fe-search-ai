<?php

namespace FEAISearch\Core;

class FEAS_AI_Sync_Hooks {

	public function __construct() {
		add_action( 'save_post', [ $this, 'sync_single_post_on_update' ], 10, 2 );
		add_action( 'wp_trash_post', [ $this, 'delete_post_from_index' ] );
		add_action( 'delete_post', [ $this, 'delete_post_from_index' ] );
	}

	/**
	 * 投稿が更新された際に、単一の投稿を同期する
	 * @param int $post_id 投稿ID.
	 * @param WP_Post $post 投稿オブジェクト.
	 */
	public function sync_single_post_on_update( $post_id, $post ) {
		$sync_options = get_option( 'feas_ai_sync_options', [] );
		$pt_options = $sync_options['post_types'][ $post->post_type ] ?? [];

		if ( empty( $pt_options['enabled'] ) ) {
			return;
		}

		if ( $post->post_status !== 'publish' ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->delete_post_from_index( $post_id );

		$chunks_with_meta = $GLOBALS['feas_ai_sync_handler']->create_chunks_from_post( $post );

		if ( empty($chunks_with_meta) ) {
			return;
		}

		$embedding_response = $GLOBALS['feas_ai_sync_handler']->get_embeddings_via_selected_provider( $chunks_with_meta );

		if ( ! is_wp_error( $embedding_response ) && !empty($embedding_response['data']) ) {
			global $wpdb;
			$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
			$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
			$segmenter = new \U7aro\TinySegmenter\TinySegmenter();

			$vectors_data = $embedding_response['data'];

			foreach ( $vectors_data as $index => $vector_item ) {
				$wpdb->insert(
					$vectors_table,
					[
						'post_id'       => $post->ID,
						'chunk_index'   => $index,
						'content_chunk' => $chunks_with_meta[$index],
						'vector_data'   => json_encode( $vector_item['embedding'] ),
						'created_at'    => current_time( 'mysql' ),
					],
					[ '%d', '%d', '%s', '%s', '%s' ]
				);
				$vector_id = $wpdb->insert_id;
				if ($vector_id) {
					$keywords = $segmenter->segment($chunks_with_meta[$index]);
					foreach ( array_unique( $keywords ) as $keyword ) {
						if ( mb_strlen( $keyword ) > 1 ) {
							$wpdb->insert(
								$index_table,
								[ 'keyword' => $keyword, 'vector_id' => $vector_id ],
								[ '%s', '%d' ]
							);
						}
					}
				}
			}
		}

		update_option( 'feas_ai_last_sync_timestamp', current_time( 'timestamp' ) );
	}

	public function delete_post_from_index( $post_id ) {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		// 削除対象の投稿に紐づくベクトルIDを取得
		$vector_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );

		if ( ! empty( $vector_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );

			// 索引テーブルから削除
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$index_table}` WHERE `vector_id` IN ( {$placeholders} )", $vector_ids ) );

			// ベクトルテーブルから削除
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );
		}
	}

}
