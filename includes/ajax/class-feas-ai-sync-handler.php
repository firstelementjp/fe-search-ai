<?php
/**
 * Handles all content synchronization and data retrieval tasks.
 *
 * This file defines the FEAS_AI_Sync_Handler class, which is responsible for
 * all processes related to indexing content, creating vector embeddings, and
 * retrieving relevant information from the database for the chat handler.
 *
 * @package    fe-ai-search
 * @subpackage Ajax
 * @since      1.0.0
 */

namespace FEAISearch\Ajax;

if ( ! defined( 'ABSPATH' ) ) exit;

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
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Sync_Handler {

	private $segmenter;

	public function __construct() {

		$this->segmenter = new \U7aro\TinySegmenter\TinySegmenter();

		add_action( 'wp_ajax_feas_ai_start_sync', array( $this, 'ajax_start_sync' ) );
		add_action( 'wp_ajax_feas_ai_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_feas_ai_update_sync_timestamp', array( $this, 'ajax_update_sync_timestamp' ) );
		add_action( 'wp_ajax_feas_ai_delete_vectors', array( $this, 'ajax_delete_vectors' ) );
	}

	public function ajax_start_sync() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		$wpdb->query( "TRUNCATE TABLE `{$index_table}`" );

		$sync_options    = get_option( 'feas_ai_sync_options', [] );
		$include_ids_str = get_option( 'feas_ai_include_post_ids', '' );
		$exclude_ids_str = get_option( 'feas_ai_exclude_post_ids', '' );
		$sync_limit      = (int) get_option( 'feas_ai_sync_limit', 0 );

		$post_types_to_sync = [];
		if ( !empty($sync_options['post_types']) && is_array($sync_options['post_types']) ) {
			foreach ($sync_options['post_types'] as $pt_slug => $pt_options) {
				if ( !empty($pt_options['enabled']) ) {
					$post_types_to_sync[] = $pt_slug;
				}
			}
		}

		$args = [
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'cache_results'  => false,
			'posts_per_page' => ($sync_limit > 0) ? $sync_limit : -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$include_ids = array_filter( array_map('intval', explode(',', $include_ids_str)) );
		$exclude_ids = array_filter( array_map('intval', explode(',', $exclude_ids_str)) );

		if ( ! empty( $include_ids ) ) {
			$args['post__in'] = $include_ids;
		} else {
			$args['post_type'] = empty($post_types_to_sync) ? ['post', 'page'] : $post_types_to_sync;
			if ( ! empty( $exclude_ids ) ) {
				$args['post__not_in'] = $exclude_ids;
			}
		}

		/**
		 * 同期対象の投稿を取得するクエリ引数をフィルターする。
		 *
		 * @param array $args WP_Query / get_posts に渡される引数の配列。
		 */
		$args = apply_filters( 'feas_ai_sync_query_args', $args );

		$all_post_ids = get_posts( $args );
		$total_posts = count($all_post_ids);

		$batch_size = 10;
		$total_pages = ceil( $total_posts / $batch_size );

		wp_send_json_success( [
			'total_pages' => $total_pages,
			'total_posts' => $total_posts,
			'post_ids'    => $all_post_ids, // ★★★ 処理対象のIDリストをJSに渡す
		] );
	}

	public function ajax_process_batch() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$post_ids_json = isset($_POST['post_ids']) ? stripslashes($_POST['post_ids']) : '[]';
		$post_ids = json_decode($post_ids_json, true);

		$batch_size = 10;

		// ★ JSから渡されたIDリストを元に、このバッチの担当分を切り出す
		$offset = ($page - 1) * $batch_size;
		$batch_ids = array_slice($post_ids, $offset, $batch_size);

		if ( empty($batch_ids) ) {
			wp_send_json_success( ['message' => 'No more posts to process.'] );
			return;
		}

		// $sql_query_catcher = function( $request ) {
		// 	error_log( '--- GENERATED SQL QUERY for get_posts ---' );
		// 	error_log( $request );
		// 	return $request;
		// };
		// add_filter( 'posts_request', $sql_query_catcher, 10, 1 );

		$posts_batch = get_posts([
			'post__in'            => $batch_ids,
			'post_type'           => 'any',
			'orderby'             => 'post__in',
			'posts_per_page'      => count($batch_ids),
			'ignore_sticky_posts' => 1,
		]);

		// remove_filter( 'posts_request', $sql_query_catcher, 10 );

		// デバッグログを出力
		// error_log('--- FE AI Batch Debug ---');
		// error_log('Page: ' . $page);
		// error_log('Batch IDs Count: ' . count($batch_ids));
		// error_log('Fetched Posts Count: ' . count($posts_batch));

		$sync_options = get_option('feas_ai_sync_options', []);

		// --- 3. 投稿をループ処理し、設定に応じてチャンクを作成・保存 ---
		// $segmenter = new TinySegmenter();
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		foreach ( $posts_batch as $post ) {

			$chunks_with_meta = $this->create_chunks_from_post( $post );

			if ( empty($chunks_with_meta) ) { continue; }

			$embedding_response = $this->get_embeddings_via_selected_provider( $chunks_with_meta );

			if ( ! is_wp_error( $embedding_response ) && !empty($embedding_response['data']) ) {
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
						$keywords = $this->tokenize_text( $chunks_with_meta[$index] );
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
		}
		wp_send_json_success( ['message' => 'Batch ' . $page . ' processed.'] );
	}

	public function ajax_update_sync_timestamp() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );
		update_option( 'feas_ai_last_sync_timestamp', current_time( 'timestamp' ) );
		wp_send_json_success();
	}

	public function ajax_delete_vectors() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		$wpdb->query( "TRUNCATE TABLE `{$index_table}`" );

		delete_option( 'feas_ai_last_sync_timestamp' );

		wp_send_json_success('すべての同期データを削除しました。');
	}

	/**
	 * タイムアウトしない、ローカル完結の高速検索メソッド
	 */
	public function find_similar_chunks( $question ) {
		global $wpdb;
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$posts_table   = $wpdb->posts;

		//$segmenter = new TinySegmenter();
		$keywords = array_unique( $this->tokenize_text( $question ) );
		$valid_keywords = array_filter($keywords, function($kw){ return mb_strlen($kw) > 1; });

		if ( empty( $valid_keywords ) ) return array();

		$placeholders = implode( ', ', array_fill( 0, count( $valid_keywords ), '%s' ) );
		$sql = "SELECT DISTINCT `vector_id` FROM `{$index_table}` WHERE `keyword` IN ( {$placeholders} ) LIMIT 500";
		$vector_ids = $wpdb->get_col( $wpdb->prepare( $sql, $valid_keywords ) );

		if ( empty( $vector_ids ) ) return array();

		$placeholders_ids = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
		$sql_candidates   = "
			SELECT `post_id`, `content_chunk`, `vector_data`, p.post_date
			FROM `{$vectors_table}` AS v
			JOIN `{$posts_table}` AS p ON v.post_id = p.ID
			WHERE v.id IN ( {$placeholders_ids} )";
		$candidate_rows   = $wpdb->get_results( $wpdb->prepare( $sql_candidates, $vector_ids ) );

		if( empty($candidate_rows) ) return array();

		$question_vector_response = $this->get_embeddings_via_selected_provider( array( $question ) );
		if( is_wp_error($question_vector_response) || empty($question_vector_response['data'][0]['embedding']) ){
			return array();
		}
		$question_vector = $question_vector_response['data'][0]['embedding'];

		$similarities = array();
		$similarity_threshold = 0.35; // 類似度の足切りスコア

		foreach ( $candidate_rows as $row ) {
			$db_vector = json_decode( $row->vector_data, true );
			if ( ! is_array( $db_vector ) || empty( $db_vector ) ) continue;

			$similarity = $this->calculate_cosine_similarity_php( $question_vector, $db_vector );

			if ( $similarity >= $similarity_threshold ) {
				$similarities[] = array(
					'post_id'       => $row->post_id,
					'content_chunk' => $row->content_chunk,
					'similarity'    => $similarity,
					'permalink'     => get_permalink( $row->post_id ),
					'post_date'     => $row->post_date,
				);
			}
		}

		if ( empty($similarities) ) {
			return array();
		}

		usort( $similarities, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		return array_slice( $similarities, 0, 7 );
	}

	public function create_chunks_from_post( $post ) {
		$sync_options = get_option('feas_ai_sync_options', []);
		$pt_options = $sync_options['post_types'][ $post->post_type ] ?? [];

		$metadata_header = '';
		if ( !empty($pt_options['include_title']) ) { $metadata_header .= "タイトル: " . $post->post_title . "\n"; }
		if ( !empty($pt_options['include_date']) ) { $metadata_header .= "投稿日: " . $post->post_date . "\n"; }

		if ( !empty($pt_options['taxonomies']) && is_array($pt_options['taxonomies']) ) {
			foreach($pt_options['taxonomies'] as $tax_slug) {
				$terms = get_the_terms($post->ID, $tax_slug);
				if ( !is_wp_error($terms) && !empty($terms) ) {
					$term_names = wp_list_pluck($terms, 'name');
					$taxonomy_obj = get_taxonomy($tax_slug);
					if ($taxonomy_obj) {
						$metadata_header .= $taxonomy_obj->label . ": " . implode(', ', $term_names) . "\n";
					}
				}
			}
		}

		if ( class_exists('FEAS_AI_Pro') && !empty($pt_options['custom_fields']) ) {
			$custom_field_keys = array_map('trim', explode(',', $pt_options['custom_fields']));
			foreach ($custom_field_keys as $key) {
				$value = get_post_meta($post->ID, $key, true);
				if ($value) { $metadata_header .= "{$key}: {$value}\n"; }
			}
		}

		$content = '';
		if ( !empty($pt_options['include_content']) ) {
			$content = strip_shortcodes( $post->post_content );
			$content = wp_strip_all_tags( $content );
		}

		$raw_chunks = empty(trim($content)) ? [''] : explode( "\n\n", $content );
		$valid_chunks = array_values( array_filter( array_map( 'trim', $raw_chunks ) ) );
		if ( empty($valid_chunks) && !empty(trim($metadata_header)) ) {
			$valid_chunks = [''];
		}
		if ( empty( $valid_chunks ) ) { return []; }

		$chunks_with_meta = [];
		foreach($valid_chunks as $chunk) {
			$chunks_with_meta[] = trim($metadata_header) . "\n---\n" . $chunk;
		}

		return $chunks_with_meta;
	}

	public function get_embeddings_via_selected_provider( $texts ) {
		$provider = get_option( 'feas_ai_embedding_provider', 'openai' );

		/**
		 * 独自のEmbeddingプロバイダー処理を割り込ませるためのフィルターフック。
		 * フック名: feas_ai_embedding_result_for_{プロバイダーのスラッグ}
		 *
		 * @param WP_Error|array|null $result   結果。nullの場合、デフォルト処理が実行される.
		 * @param array               $texts    ベクトル化するテキストの配列.
		 */
		$result = apply_filters( "feas_ai_embedding_result_for_{$provider}", null, $texts );

		if ( null !== $result ) {
			return $result;
		}

		switch ($provider) {
			case 'google':
				return $this->fetch_embeddings_for_gemini( $texts );
			case 'openai':
			default:
				return $this->fetch_embeddings_for_openai( $texts );
		}
	}

	public function calculate_cosine_similarity_php( $vec1, $vec2 ) {
		$dot_product = 0;
		$norm1 = 0;
		$norm2 = 0;
		$count = count( $vec1 );

		for ( $i = 0; $i < $count; $i++ ) {
			$dot_product += $vec1[$i] * $vec2[$i];
			$norm1 += $vec1[$i] * $vec1[$i];
			$norm2 += $vec2[$i] * $vec2[$i];
		}

		if ( $norm1 == 0 || $norm2 == 0 ) {
			return 0.0;
		}

		return $dot_product / ( sqrt( $norm1 ) * sqrt( $norm2 ) );
	}

	public function fetch_embeddings_for_openai( $texts ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'OpenAI APIキーが設定されていません。' );
		}

		$api_url = 'https://api.openai.com/v1/embeddings';

		$body = array(
			'model' => 'text-embedding-3-small', // 最新の安価で高性能なモデル
			'input' => $texts,
		);

		$response = wp_remote_post( $api_url, array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'        => json_encode( $body ),
			'timeout'     => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			$error_message = isset( $response_body['error']['message'] )
				? $response_body['error']['message']
				: '不明なAPIエラー';
			return new WP_Error( 'api_error', 'APIエラー: ' . $error_message );
		}

		return $response_body;
	}

	public function get_claude_embeddings( $texts ) {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'Anthropic APIキーが設定されていません。' );
		}

		$api_url = 'https://api.anthropic.com/v1/embeddings'; // (注: 2025年9月時点ではClaudeに専用Embedding APIはありません。これは将来的な実装を見越したダミーです)

		// 現状ClaudeにはEmbedding APIがないため、ダミーのエラーを返す
		return new WP_Error('not_implemented', 'ClaudeのEmbedding機能は現在サポートされていません。OpenAIまたはGeminiを選択してください。');

		// TODO
	}

	public function fetch_embeddings_for_gemini( $texts ) {
		$api_key = get_option( 'feas_ai_google_api_key' ); // Google用のAPIキーを取得
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'Google Cloud APIキーが設定されていません。' );
		}

		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:batchEmbedContents?key=' . $api_key;

		// リクエストボディの構造が異なる
		$requests = array();
		foreach ( $texts as $text ) {
			$requests[] = array(
				'model' => 'models/text-embedding-004',
				'content' => array(
					'parts' => array(
						array( 'text' => $text )
					)
				)
			);
		}
		$body = array( 'requests' => $requests );

		$response = wp_remote_post( $api_url, array(
			'headers' => array( 'Content-Type'  => 'application/json' ), // ヘッダーの形式が異なる
			'body'    => json_encode( $body ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			$error_message = isset( $response_body['error']['message'] )
				? $response_body['error']['message']
				: '不明なAPIエラー';
			return new WP_Error( 'api_error', 'APIエラー: ' . $error_message );
		}

		// レスポンスからベクトルデータを取り出す構造が異なる
		$vectors = array();
		if ( isset( $response_body['embeddings'] ) ) {
			foreach ( $response_body['embeddings'] as $embedding_data ) {
				$vectors[] = $embedding_data['values'];
			}
		}

		// OpenAI版とレスポンスの形を揃えるため、独自に整形して返す
		$formatted_response = array();
		foreach( $vectors as $i => $vector ) {
			$formatted_response['data'][$i]['embedding'] = $vector;
		}

		return $formatted_response;
	}

	/**
	 * テキストを言語に応じてキーワードに分割する
	 * @param string $text 分割するテキスト.
	 * @return array キーワードの配列.
	 */
	private function tokenize_text( $text ) {
		$locale = get_locale();

		if ( 'ja' === $locale ) {
			// --- 日本語の場合 ---
			$words = $this->segmenter->segment( $text );

			// 日本語用のストップワードリストを定義（ひらがな1文字の助詞など）
			$ja_stop_words = [
				'の', 'に', 'は', 'を', 'た', 'が', 'で', 'て', 'と', 'し', 'れ', 'さ',
				'ある', 'いる', 'する', 'です', 'ます', 'でした', 'ました',
				'これ', 'それ', 'あれ', 'この', 'その', 'あの', 'ここ', 'そこ', 'あそこ',
				'もの', 'こと', 'とき', 'ところ',
			];

			// ストップワードを除去
			$words = array_diff($words, $ja_stop_words);

			return array_values($words); // 配列のキーを振り直して返す

		} else {
			// --- その他の言語の場合 ---
			$stop_words = [ 'a', 'an', 'the', 'is', 'in', 'it', 'of', 'for', 'on', 'with', 'to', 'and', 'or', 'but' ];
			$words = preg_split('/[^\p{L}\p{N}]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
			$words = array_diff($words, $stop_words);

			try {
				$stemmerManager = new \Wamania\Snowball\StemmerManager();
				$lang_code = substr($locale, 0, 2);
				if ( in_array($lang_code, $stemmerManager->getAvailableLanguages()) ) {
					$stemmer = $stemmerManager->create($lang_code);
					$stemmed_words = [];
					foreach ($words as $word) {
						$stemmed_words[] = $stemmer->stem($word);
					}
					return $stemmed_words;
				}
			} catch (\Exception $e) {
				error_log('Stemmer error: ' . $e->getMessage());
				return array_values($words);
			}

			return array_values($words);
		}
	}

}
