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

	private const BATCH_SIZE = 10;
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
		 * Filters the query arguments used to fetch posts for synchronization.
		 *
		 * This hook allows developers to add custom query parameters (e.g., meta_query,
		 * category__in) to modify which posts are selected for indexing by the AI.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args The array of query arguments passed to `get_posts()`.
		 */
		$args = apply_filters( 'feas_ai_sync_query_args', $args );

		$all_post_ids = get_posts( $args );
		$total_posts = count($all_post_ids);

		$batch_size = (int) get_option( 'feas_ai_batch_size', 10 );
		$total_pages = ceil( $total_posts / $batch_size );

		wp_send_json_success( [
			'total_pages' => $total_pages,
			'total_posts' => $total_posts,
			'post_ids'    => $all_post_ids, // Pass the list of IDs to be processed to JS
			'batch_size'  => $batch_size,
		] );
	}

	public function ajax_process_batch() {
		// PHPの実行が、どんなエラーでも中断されないようにする
		try {
			set_time_limit(0);
			ob_start(); // これ以降の予期せぬ出力をすべてバッファに溜める

			error_log('--- ajax_process_batch: START ---');
			check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

			// --- 1. 入力データを処理 ---
			$page          = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
			$post_ids_json = isset( $_POST['post_ids'] ) ? stripslashes( $_POST['post_ids'] ) : '[]';
			$post_ids      = json_decode( $post_ids_json, true );
			$batch_size    = (int) get_option( 'feas_ai_batch_size', 10 );
			$offset        = ( $page - 1 ) * $batch_size;
			$batch_ids     = array_slice( $post_ids, $offset, $batch_size );
			error_log("1. Processing Batch {$page}. Batch IDs (" . count($batch_ids) . " items): " . print_r($batch_ids, true));

			if ( empty( $batch_ids ) ) {
				ob_end_clean();
				wp_send_json_success( [ 'message' => 'No more posts to process.' ] );
				return;
			}

			// --- 2. 投稿データを取得 ---
			$posts_batch = get_posts([
				'post__in'            => $batch_ids,
				'post_type'           => 'any',
				'orderby'             => 'post__in',
				'posts_per_page'      => count( $batch_ids ),
				'ignore_sticky_posts' => 1,
				'post_status'         => 'publish',
			]);
			error_log("2. get_posts() found " . count($posts_batch) . " posts for this batch.");

			// --- 3. 各投稿を処理 ---
			foreach ( $posts_batch as $post ) {
				error_log("  - Processing Post ID: {$post->ID}");

				$chunks_with_meta = $this->create_chunks_from_post( $post );
				if ( empty( $chunks_with_meta ) ) {
					error_log("    - No chunks created. Skipping.");
					continue;
				}
				error_log("    - Created " . count($chunks_with_meta) . " chunks.");

				$chunks_for_embedding = wp_list_pluck( $chunks_with_meta, 'content_chunk' );
				$embedding_response   = $this->get_embeddings_via_selected_provider( $chunks_for_embedding );
				error_log("    - Embedding API Response: " . print_r($embedding_response, true));

				if ( ! is_wp_error( $embedding_response ) && ! empty( $embedding_response['data'] ) ) {
					error_log("    - Embedding successful. Starting DB insert...");
					global $wpdb;
					$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
					$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
					$vectors_data  = $embedding_response['data'];

					foreach ( $vectors_data as $index => $vector_item ) {
						if ( empty( $vector_item['embedding'] ) ) continue;

						$chunk_item = $chunks_with_meta[ $index ];

						$wpdb->insert(
							$vectors_table,
							[
								'post_id'       => $post->ID,
								'content_chunk' => $chunk_item['content_chunk'],
								'vector_data'   => wp_json_encode( $vector_item['embedding'] ),
								'created_at'    => current_time( 'mysql' ),
							]
						);
						$vector_id = $wpdb->insert_id;
						error_log("      - Inserted vector. New vector_id: " . $vector_id);

						if ($vector_id) {
							$keywords = $this->tokenize_text( $chunk_item['content_chunk'] );
							$unique_keywords = array_unique( $keywords );
							error_log("      - Tokenized to " . count($unique_keywords) . " unique keywords.");

							foreach ( $unique_keywords as $keyword ) {
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
				} else {
					error_log("    - Embedding FAILED or returned empty data. Skipping DB insert.");
				}
			}

			ob_end_clean(); // 成功時も、意図しない出力をすべて破棄
			wp_send_json_success( [ 'message' => 'Batch ' . $page . ' processed.' ] );

		} catch ( \Throwable $t ) {
			error_log( '--- FATAL ERROR in ajax_process_batch ---' );
			error_log( 'Error Message: ' . $t->getMessage() );
			error_log( 'File: ' . $t->getFile() . ' on line ' . $t->getLine() );

			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			if ( ! headers_sent() ) {
				wp_send_json_error( [ 'message' => 'A fatal error occurred on the server: ' . $t->getMessage() ] );
			}
		}
	}

	// public function ajax_process_batch() {
	// 	check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );
//
	// 	$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
	// 	$post_ids_json = isset($_POST['post_ids']) ? stripslashes($_POST['post_ids']) : '[]';
	// 	$post_ids = json_decode($post_ids_json, true);
//
	// 	$offset = ($page - 1) * self::BATCH_SIZE;
	// 	$batch_ids = array_slice($post_ids, $offset, self::BATCH_SIZE);
//
	// 	// Extract the part of this batch based on the ID list passed from JS
	// 	$batch_size = (int) get_option( 'feas_ai_batch_size', 10 );
	// 	$offset = ($page - 1) * $batch_size;
	// 	$batch_ids = array_slice($post_ids, $offset, $batch_size);
//
	// 	if ( empty($batch_ids) ) {
	// 		wp_send_json_success( ['message' => 'No more posts to process.'] );
	// 		return;
	// 	}
//
	// 	$posts_batch = get_posts([
	// 		'post__in'            => $batch_ids,
	// 		'post_type'           => 'any',
	// 		'orderby'             => 'post__in',
	// 		'posts_per_page'      => count($batch_ids),
	// 		'ignore_sticky_posts' => 1,
	// 	]);
//
	// 	$sync_options = get_option('feas_ai_sync_options', []);
//
	// 	// Loop through the posts and create and save chunks according to your preferences
	// 	// $segmenter = new TinySegmenter();
	// 	global $wpdb;
	// 	$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
	// 	$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
//
	// 	foreach ( $posts_batch as $post ) {
//
	// 		$chunks_with_meta = $this->create_chunks_from_post( $post );
//
	// 		if ( empty($chunks_with_meta) ) { continue; }
//
	// 		$embedding_response = $this->get_embeddings_via_selected_provider( $chunks_with_meta );
//
	// 		if ( ! is_wp_error( $embedding_response ) && !empty($embedding_response['data']) ) {
	// 			$vectors_data = $embedding_response['data'];
	// 			foreach ( $vectors_data as $index => $vector_item ) {
	// 				$wpdb->insert(
	// 					$vectors_table,
	// 					[
	// 						'post_id'       => $post->ID,
	// 						'chunk_index'   => $index,
	// 						'content_chunk' => $chunks_with_meta[$index],
	// 						'vector_data'   => json_encode( $vector_item['embedding'] ),
	// 						'created_at'    => current_time( 'mysql' ),
	// 					],
	// 					[ '%d', '%d', '%s', '%s', '%s' ]
	// 				);
	// 				$vector_id = $wpdb->insert_id;
	// 				if ($vector_id) {
	// 					$keywords = $this->tokenize_text( $chunks_with_meta[$index] );
	// 					foreach ( array_unique( $keywords ) as $keyword ) {
	// 						if ( mb_strlen( $keyword ) > 1 ) {
	// 							$wpdb->insert(
	// 								$index_table,
	// 								[ 'keyword' => $keyword, 'vector_id' => $vector_id ],
	// 								[ '%s', '%d' ]
	// 							);
	// 						}
	// 					}
	// 				}
	// 			}
	// 		}
	// 	}
	// 	wp_send_json_success( ['message' => 'Batch ' . $page . ' processed.'] );
	// }

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

		wp_send_json_success( __( 'All sync data has been deleted.', 'fe-ai-search' ) );
	}

	/**
	 * A fast, local search method that doesn't time out
	 */
	/**
	 * Finds text chunks similar to the user's question based on keyword matching.
	 *
	 * @param string $question The user's question.
	 * @return array An array of the most relevant text chunks.
	 */
	public function find_similar_chunks( $question ) {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		// 1. Use the finalized tokenize_text method to extract keywords from the question.
		$keywords = array_unique( $this->tokenize_text( $question ) );

		if ( empty( $keywords ) ) {
			return [];
		}

		// Remove keywords that are too short to be meaningful.
		$valid_keywords = array_filter($keywords, function($kw) {
			return mb_strlen($kw) > 1;
		});

		if ( empty( $valid_keywords ) ) {
			return [];
		}

		// 2. Find vector IDs that match the keywords.
		$placeholders = implode( ', ', array_fill( 0, count( $valid_keywords ), '%s' ) );
		$sql          = "SELECT DISTINCT `vector_id` FROM `{$index_table}` WHERE `keyword` IN ( {$placeholders} ) LIMIT 500";
		$vector_ids   = $wpdb->get_col( $wpdb->prepare( $sql, $valid_keywords ) );

		if ( empty( $vector_ids ) ) {
			return [];
		}

		// 3. Retrieve the full content chunks for the found vector IDs.
		$placeholders   = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
		$sql            = "SELECT `content_chunk`, `post_id` FROM `{$vectors_table}` WHERE `id` IN ( {$placeholders} )";
		$chunks_data    = $wpdb->get_results( $wpdb->prepare( $sql, $vector_ids ), ARRAY_A );

		// 4. Add permalink to the results.
		$results = [];
		foreach($chunks_data as $row){
			$results[] = [
				'content_chunk' => $row['content_chunk'],
				'permalink'     => get_permalink($row['post_id']),
			];
		}

		return $results;
	}
	// public function find_similar_chunks( $question ) {
	// 	global $wpdb;
	// 	$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
	// 	$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
	// 	$posts_table   = $wpdb->posts;
//
	// 	//$segmenter = new TinySegmenter();
	// 	$keywords = array_unique( $this->tokenize_text( $question ) );
	// 	$valid_keywords = array_filter($keywords, function($kw){ return mb_strlen($kw) > 1; });
//
	// 	if ( empty( $valid_keywords ) ) return array();
//
	// 	// Polylangの場合: pll_current_language() で現在の言語コードを取得
	// 	$current_lang = function_exists('pll_current_language') ? pll_current_language() : get_locale();
//
	// 	$placeholders = implode( ', ', array_fill( 0, count( $valid_keywords ), '%s' ) );
	// 	$sql = "SELECT DISTINCT `vector_id` FROM `{$index_table}` WHERE `lang` = %s AND `keyword` IN ( {$placeholders} ) LIMIT 500";
	// 	$vector_ids = $wpdb->get_col( $wpdb->prepare( $sql, $current_lang, $valid_keywords ) );
//
	// 	if ( empty( $vector_ids ) ) return array();
//
	// 	$placeholders_ids = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
	// 	$sql_candidates   = "
	// 		SELECT `post_id`, `content_chunk`, `vector_data`, p.post_date
	// 		FROM `{$vectors_table}` AS v
	// 		JOIN `{$posts_table}` AS p ON v.post_id = p.ID
	// 		WHERE v.id IN ( {$placeholders_ids} )";
	// 	$candidate_rows   = $wpdb->get_results( $wpdb->prepare( $sql_candidates, $vector_ids ) );
//
	// 	if( empty($candidate_rows) ) return array();
//
	// 	$question_vector_response = $this->get_embeddings_via_selected_provider( array( $question ) );
	// 	if( is_wp_error($question_vector_response) || empty($question_vector_response['data'][0]['embedding']) ){
	// 		return array();
	// 	}
	// 	$question_vector = $question_vector_response['data'][0]['embedding'];
//
	// 	$similarities = array();
	// 	$similarity_threshold = 0.35; // Similarity cutoff score
//
	// 	foreach ( $candidate_rows as $row ) {
	// 		$db_vector = json_decode( $row->vector_data, true );
	// 		if ( ! is_array( $db_vector ) || empty( $db_vector ) ) continue;
//
	// 		$similarity = $this->calculate_cosine_similarity_php( $question_vector, $db_vector );
//
	// 		if ( $similarity >= $similarity_threshold ) {
	// 			$similarities[] = array(
	// 				'post_id'       => $row->post_id,
	// 				'content_chunk' => $row->content_chunk,
	// 				'similarity'    => $similarity,
	// 				'permalink'     => get_permalink( $row->post_id ),
	// 				'post_date'     => $row->post_date,
	// 			);
	// 		}
	// 	}
//
	// 	if ( empty($similarities) ) {
	// 		return array();
	// 	}
//
	// 	usort( $similarities, function( $a, $b ) {
	// 		return $b['similarity'] <=> $a['similarity'];
	// 	} );
//
	// 	return array_slice( $similarities, 0, 7 );
	// }

	// public function create_chunks_from_post( $post ) {
	// 	$sync_options = get_option('feas_ai_sync_options', []);
	// 	$pt_options = $sync_options['post_types'][ $post->post_type ] ?? [];
//
	// 	$metadata_header = '';
	// 	if ( !empty($pt_options['include_title']) ) { $metadata_header .= "タイトル: " . $post->post_title . "\n"; }
	// 	if ( !empty($pt_options['include_date']) ) { $metadata_header .= "投稿日: " . $post->post_date . "\n"; }
//
	// 	if ( !empty($pt_options['taxonomies']) && is_array($pt_options['taxonomies']) ) {
	// 		foreach($pt_options['taxonomies'] as $tax_slug) {
	// 			$terms = get_the_terms($post->ID, $tax_slug);
	// 			if ( !is_wp_error($terms) && !empty($terms) ) {
	// 				$term_names = wp_list_pluck($terms, 'name');
	// 				$taxonomy_obj = get_taxonomy($tax_slug);
	// 				if ($taxonomy_obj) {
	// 					$metadata_header .= $taxonomy_obj->label . ": " . implode(', ', $term_names) . "\n";
	// 				}
	// 			}
	// 		}
	// 	}
//
	// 	if ( class_exists('FEAS_AI_Pro') && !empty($pt_options['custom_fields']) ) {
	// 		$custom_field_keys = array_map('trim', explode(',', $pt_options['custom_fields']));
	// 		foreach ($custom_field_keys as $key) {
	// 			$value = get_post_meta($post->ID, $key, true);
	// 			if ($value) { $metadata_header .= "{$key}: {$value}\n"; }
	// 		}
	// 	}
//
	// 	$content = '';
	// 	if ( !empty($pt_options['include_content']) ) {
	// 		$content = strip_shortcodes( $post->post_content );
	// 		$content = wp_strip_all_tags( $content );
	// 	}
//
	// 	$raw_chunks = empty(trim($content)) ? [''] : explode( "\n\n", $content );
	// 	$valid_chunks = array_values( array_filter( array_map( 'trim', $raw_chunks ) ) );
	// 	if ( empty($valid_chunks) && !empty(trim($metadata_header)) ) {
	// 		$valid_chunks = [''];
	// 	}
	// 	if ( empty( $valid_chunks ) ) { return []; }
//
	// 	$chunks_with_meta = [];
	// 	foreach($valid_chunks as $chunk) {
	// 		$chunks_with_meta[] = trim($metadata_header) . "\n---\n" . $chunk;
	// 	}
//
	// 	return $chunks_with_meta;
	// }


	/**
	 * Creates text chunks from a post object, combining metadata into prose.
	 *
	 * @param \WP_Post $post The post object to process.
	 * @return array An array of text chunks.
	 */
	public function create_chunks_from_post( $post ) {
		$options    = get_option( 'feas_ai_sync_options', [] );
		$pt_options = $options['post_types'][ $post->post_type ] ?? [];

		$text_parts = [];

		// 1. Build an array of sentences from metadata.
		if ( ! empty( $pt_options['include_title'] ) ) {
			$text_parts[] = sprintf( 'この記事のタイトルは「%s」です。', $post->post_title );
		}
		if ( ! empty( $pt_options['include_date'] ) ) {
			$text_parts[] = sprintf( 'この記事は%sに公開されました。', date_i18n( get_option( 'date_format' ), strtotime( $post->post_date ) ) );
		}
		if ( ! empty( $pt_options['include_author'] ) ) {
			$author_name  = get_the_author_meta( 'display_name', $post->post_author );
			$text_parts[] = sprintf( '著者は%sです。', $author_name );
		}

		// Add taxonomies as keywords.
		if ( ! empty( $pt_options['taxonomies'] ) ) {
			$term_list = [];
			foreach ( $pt_options['taxonomies'] as $tax_slug ) {
				$terms = get_the_terms( $post->ID, $tax_slug );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$term_list = array_merge( $term_list, wp_list_pluck( $terms, 'name' ) );
				}
			}
			if ( ! empty( $term_list ) ) {
				$text_parts[] = sprintf( '関連キーワードは「%s」です。', implode( '、', $term_list ) );
			}
		}

		// 2. Add the main content.
		if ( ! empty( $pt_options['include_content'] ) ) {
			$content = strip_shortcodes( $post->post_content );
			$content = wp_strip_all_tags( $content );
			$text_parts[] = "以下は記事の本文です。\n" . $content;
		}

		if ( empty( $text_parts ) ) {
			return [];
		}

		// 3. Join all parts into a single block of text.
		$full_text = implode( "\n", $text_parts );

		// 4. Split the full text into chunks.
		$chunk_size = 1000; // characters
		$chunks     = [];
		if ( mb_strlen( $full_text ) > $chunk_size ) {
			$sentences = preg_split('/(?<=[。．！？\n])\s*/u', $full_text, -1, PREG_SPLIT_NO_EMPTY);
			$current_chunk = '';
			foreach ( $sentences as $sentence ) {
				if ( mb_strlen( $current_chunk . $sentence ) > $chunk_size ) {
					$chunks[] = trim( $current_chunk );
					$current_chunk = $sentence;
				} else {
					$current_chunk .= $sentence;
				}
			}
			if ( ! empty( $current_chunk ) ) {
				$chunks[] = trim( $current_chunk );
			}
		} else {
			$chunks[] = trim( $full_text );
		}

		$permalink = get_permalink( $post );
		$chunks_with_meta = [];
		foreach( $chunks as $chunk_content ){
			$chunks_with_meta[] = [
				'content_chunk' => $chunk_content,
				'permalink'     => $permalink,
			];
		}

		return $chunks_with_meta;
	}


	public function get_embeddings_via_selected_provider( $texts ) {
		$provider = get_option( 'feas_ai_embedding_provider', 'openai' );

		/**
		 * A filter hook for interposing your own embedding provider processing.
		 * Hook name: feas_ai_embedding_result_for_{provider slug}
		 *
		 * @param WP_Error|array|null $result Result. If null, the default processing will be performed.
		 * @param array               $texts  Array of text to vectorize.
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
			return new WP_Error( 'api_key_missing', __( 'The OpenAI API key is not configured.', 'fe-ai-search' ) );
		}

		$api_url = 'https://api.openai.com/v1/embeddings';

		$body = array(
			'model' => 'text-embedding-3-small',
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
				: __( 'Unknown API Error', 'fe-ai-search' );
			return new WP_Error( 'api_error', __( 'API Error', 'fe-ai-search' ) . ': ' . $error_message );
		}

		return $response_body;
	}

	public function fetch_embeddings_for_gemini( $texts ) {
		$api_key = get_option( 'feas_ai_google_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', __( 'The Google Cloud API key is not configured.', 'fe-ai-search' ) );
		}

		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:batchEmbedContents?key=' . $api_key;

		// The request body structure is different
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
			'headers' => array( 'Content-Type'  => 'application/json' ),
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
				: __( 'Unknown API Error', 'fe-ai-search' );
			return new WP_Error( 'api_error', __( 'API Error', 'fe-ai-search' ) . ': ' . $error_message );
		}

		// The structure for extracting vector data from the response is different
		$vectors = array();
		if ( isset( $response_body['embeddings'] ) ) {
			foreach ( $response_body['embeddings'] as $embedding_data ) {
				$vectors[] = $embedding_data['values'];
			}
		}

		// To align the response format with the OpenAI version, we format it ourselves and return it.
		$formatted_response = array();
		foreach( $vectors as $i => $vector ) {
			$formatted_response['data'][$i]['embedding'] = $vector;
		}

		return $formatted_response;
	}

	public function get_claude_embeddings( $texts ) {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', __( 'Anthropic API key is not set.', 'fe-ai-search' ) );
		}

		$api_url = 'https://api.anthropic.com/v1/embeddings';

		// 現状ClaudeにはEmbedding APIがないため、ダミーのエラーを返す
		return new WP_Error('not_implemented', __( "Claude's embedding feature is currently not supported. Please choose OpenAI or Gemini.", 'fe-ai-search' ));

		// TODO
	}

	/**
	 * Split text into keywords according to language.
	 * @param  string $text The text to split.
	 * @return array  An array of keywords.
	 */
	// private function tokenize_text( $text ) {
	// 	$text = strtolower( $text );
	// 	$text = mb_convert_kana( $text, 'as' );
//
	// 	$locale = get_locale();
	// 	$lang_code = strstr( $locale, '_', true ) ?: $locale; // 'ja', 'en' etc.
	// 	$stop_words = [];
//
	// 	// Find the language file and load the stop words
	// 	$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$locale}.php";
	// 	if ( ! file_exists( $lang_file ) ) {
	// 		$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
	// 	}
	// 	if ( file_exists( $lang_file ) ) {
	// 		$stop_words = include( $lang_file );
	// 	}
//
	// 	/**
	// 	 * Filters the array of stop words used during keyword extraction.
	// 	 *
	// 	 * This hook allows developers to add or remove stop words for a specific
	// 	 * language, or to replace the default list entirely.
	// 	 *
	// 	 * @since 1.0.0
	// 	 *
	// 	 * @param array  $stop_words The array of stop words to be filtered.
	// 	 * @param string $locale     The current site locale (e.g., 'en_US', 'ja').
	// 	 */
	// 	$stop_words = apply_filters( 'feas_ai_stop_words', $stop_words, $locale );
//
	// 	if ( 'ja' !== $lang_code && 'en' !== $lang_code ) {
	// 		if ( ! get_transient( 'feas_ai_i18n_notice_dismissed' ) ) {
	// 			add_action( 'admin_notices', [ $this, 'render_i18n_notice' ] );
	// 		}
	// 	}
//
	// 	// Split text into words
	// 	if ( 'ja' === $lang_code ) {
	// 		$words = $this->segmenter->segment( $text );
	// 		$words = array_map('strtolower', $words);
	// 	} else {
	// 		$words = preg_split('/[^\p{L}\p{N}]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
	// 	}
//
	// 	// Remove stop words
	// 	$words = array_diff($words, $stop_words);
//
	// 	// Stemming (only in supported languages, such as English)
	// 	if ( 'ja' !== $lang_code ) {
	// 		try {
	// 			$stemmerManager = new \Wamania\Snowball\StemmerManager();
	// 			if ( in_array($lang_code, $stemmerManager->getAvailableLanguages()) ) {
	// 				$stemmer = $stemmerManager->create($lang_code);
	// 				$stemmed_words = [];
	// 				foreach ($words as $word) {
	// 					$stemmed_words[] = $stemmer->stem($word);
	// 				}
	// 				$words = $stemmed_words;
	// 			}
	// 		} catch (\Exception $e) {
	// 			error_log('Stemmer error: ' . $e->getMessage());
	// 		}
	// 	}
//
	// 	return array_values($words);
	// }
	// private function tokenize_text( $text ) {
	// 	error_log('--- tokenize_text: START ---');
	// 	error_log('1. Input text type: ' . gettype($text));
	// 	// error_log('1. Input text value: ' . print_r($text, true)); // This can be very long, so commented out for now.
//
	// 	// Normalization
	// 	$text_normalized = mb_strtolower( (string) $text, 'UTF-8' );
	//
	// 	// 2. 全角の英数字・スペースを半角に、半角カタカナを全角カタカナに変換
	// 	// 'a': 全角英数を半角に
	// 	// 's': 全角スペースを半角に
	// 	// 'K': 半角カタカナを全角カタカナに
	// 	// 'V': 濁点・半濁点を結合
	// 	$text = mb_convert_kana( $text, 'asKV', 'UTF-8' );
//
	// 	// 3. すべての全角カタカナを、全角ひらがなに統一
	// 	// 'C': 全角カタカナを全角ひらがなに
	// 	$text = mb_convert_kana( $text, 'C', 'UTF-8' );
	// 	// 3. 記号や句読点をスペースに置換
	// 	$text_normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
//
	// 	error_log('2. Normalized text: ' . $text_normalized);
//
	// 	$locale = get_locale();
	// 	$lang_code = strstr( $locale, '_', true ) ?: $locale;
	// 	$stop_words = [];
//
	// 	// ★ 2. Load stop words
	// 	$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$locale}.php";
	// 	if ( ! file_exists( $lang_file ) ) {
	// 		$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
	// 	}
	// 	if ( file_exists( $lang_file ) ) {
	// 		$lang_data = include( $lang_file );
	// 		$stop_words = $lang_data['stop_words'] ?? [];
	// 	}
	// 	error_log('3. Stop words type: ' . gettype($stop_words));
//
	// 	// ★ 3. Tokenize the cleaned text
	// 	if ( 'ja' === $lang_code ) {
	// 		$words = $this->segmenter->segment( $text_normalized );
	// 	} else {
	// 		$words = preg_split('/\s+/', $text_normalized, -1, PREG_SPLIT_NO_EMPTY);
	// 	}
	// 	error_log('4. Words after tokenization type: ' . gettype($words));
	// 	error_log('4. Words after tokenization value: ' . print_r($words, true));
//
	// 	// ★ 4. Remove stop words
	// 	$words_after_diff = array_diff( (array) $words, (array) $stop_words );
	// 	error_log('5. Words after array_diff: ' . print_r($words_after_diff, true));
//
	// 	// ★ 5. Stemming for non-Japanese languages
	// 	if ( 'ja' !== $lang_code ) {
	// 		try {
	// 			$stemmerManager = new \Wamania\Snowball\StemmerManager();
	// 			if ( in_array($lang_code, $stemmerManager->getAvailableLanguages()) ) {
	// 				$stemmer = $stemmerManager->create($lang_code);
	// 				$stemmed_words = [];
	// 				foreach ($words_after_diff as $word) {
	// 					$stemmed_words[] = $stemmer->stem($word);
	// 				}
	// 				$words_after_diff = $stemmed_words;
	// 				error_log('6. Words after stemming: ' . print_r($words_after_diff, true));
	// 			}
	// 		} catch (\Exception $e) {
	// 			error_log('Stemmer error: ' . $e->getMessage());
	// 		}
	// 	}
//
	// 	error_log('--- tokenize_text: END ---');
	// 	return array_values($words_after_diff);
	// }
	private function tokenize_text( $text ) {
		error_log('--- tokenize_text: START ---');
		error_log('1. Input text type: ' . gettype($text));
		// error_log('1. Input text value: ' . print_r($text, true)); // This can be very long.

		// Normalization
		// --- Step 1: Basic Normalization ---
		// First, convert the entire text to lowercase.
		$text_normalized = mb_strtolower( (string) $text, 'UTF-8' );

		// --- Step 3: Normalize Alphanumeric Characters and Spaces ---
		// 'a': Converts full-width alphanumeric characters to half-width.
		// 's': Converts full-width spaces to half-width.
		$text_normalized = mb_convert_kana( $text_normalized, 'Hca', 'UTF-8' );

		// --- Step 2: Unify Japanese Kana to HIRAGANA ---
		// This is a two-step process to handle all variations.
		// 'K': Converts half-width katakana to full-width katakana.
		// 'V': Combines voiced sound marks (dakuten/handakuten).
		// $text_normalized = mb_convert_kana( $text_normalized, 'KV', 'UTF-8' );
		// 'C': Converts the now-unified full-width katakana to full-width hiragana.
		// $text_normalized = mb_convert_kana( $text_normalized, 'C', 'UTF-8' );


		$text_normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text_normalized);
		error_log('2. Normalized text: ' . $text_normalized);

		$locale     = get_locale();
		$lang_code  = strstr( $locale, '_', true ) ?: $locale;
		$stop_words = [];

		// Load stop words
		$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$locale}.php";
		if ( ! file_exists( $lang_file ) ) {
			$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
		}
		if ( file_exists( $lang_file ) ) {
			$lang_data  = include( $lang_file );
			$stop_words = $lang_data['stop_words'] ?? [];
		}
		// Add filter for stop words
		$stop_words = apply_filters( 'feas_ai_stop_words', $stop_words, $locale );
		error_log('3. Stop words type: ' . gettype($stop_words));

		// Tokenize
		if ( 'ja' === $lang_code ) {
			$words = $this->segmenter->segment( $text_normalized );
		} else {
			$words = preg_split('/\s+/', $text_normalized, -1, PREG_SPLIT_NO_EMPTY);
		}
		error_log('4. Words after tokenization type: ' . gettype($words));
		// error_log('4. Words after tokenization value: ' . print_r($words, true));

		// Remove stop words
		$words_after_diff = array_diff( (array) $words, (array) $stop_words );
		// error_log('5. Words after array_diff: ' . print_r($words_after_diff, true));

		// Stemming
		if ( 'ja' !== $lang_code ) {
			try {
				$stemmerManager = new \Wamania\Snowball\StemmerManager();
				if ( in_array($lang_code, $stemmerManager->getAvailableLanguages()) ) {
					$stemmer = $stemmerManager->create($lang_code);
					$stemmed_words = [];
					foreach ($words_after_diff as $word) {
						$stemmed_words[] = $stemmer->stem($word);
					}
					$words_after_diff = $stemmed_words;
					// error_log('6. Words after stemming: ' . print_r($words_after_diff, true));
				}
			} catch (\Throwable $t) {
				error_log('Stemmer error: ' . $t->getMessage());
			}
		}

		if ( ! empty( $words_after_diff ) ) {
			$words_after_diff = array_filter( $words_after_diff, function( $words_after_diff ) {
				return ! empty( trim( $words_after_diff ) );
			} );
		}

		$final_words = array_values($words_after_diff);

		/**
		 * Filters the array of keywords extracted from a text.
		 *
		 * This allows developers to implement their own language support or
		 * replace the default tokenizer entirely.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $words  The array of processed keywords.
		 * @param string $text   The original, unmodified text.
		 * @param string $locale The current site locale.
		 */
		$final_words = apply_filters( 'feas_ai_tokenize_text', $final_words, $text, $locale );

		error_log('--- tokenize_text: END ---');
		return $final_words;
	}

	/**
	 * Renders an administrator notification about internationalization
	 */
	public function render_i18n_notice() {
		// What happens when a user clicks on a "hidden" link?
		if ( isset( $_GET['feas-ai-dismiss-i18n-notice'] ) ) {
			// Flag to hide this notification for one week
			set_transient( 'feas_ai_i18n_notice_dismissed', true, WEEK_IN_SECONDS );
			return;
		}
		?>
		<div class="notice notice-info is-dismissible" data-dismiss-url="<?php echo esc_url( add_query_arg( 'feas-ai-dismiss-i18n-notice', '1' ) ); ?>">
			<p>
				<b>FE AI Search:</b> <?php esc_html_e( 'Your site language is not fully optimized for keyword search. We welcome contributions for new languages!', 'fe-ai-search' ); ?>
				<a href="https://github.com/firstelementjp/fe-ai-search" target="_blank" style="margin-left: 10px;"><?php esc_html_e( 'Contribute on GitHub', 'fe-ai-search' ); ?></a>
			</p>
		</div>
		<script>
			// Added the ability to flag hidden links without reloading the page
			jQuery(function($){
				$('div[data-dismiss-url]').on('click', '.notice-dismiss', function(e){
					e.preventDefault();
					$.post(ajaxurl, { action: 'dismiss-wp-pointer', pointer: 'feas_ai_i18n_notice_dismissed' });
					$(this).closest('.notice').fadeOut();
				});
			});
		</script>
		<?php
	}

}
