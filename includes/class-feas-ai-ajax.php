<?php

use U7aro\TinySegmenter\TinySegmenter;

class FEAS_AI_Ajax {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_stream_endpoint' ) );
		add_action( 'wp_ajax_nopriv_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_start_sync', array( $this, 'ajax_start_sync' ) );
		add_action( 'wp_ajax_feas_ai_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	/**
	 * ストリーミング用のREST APIエンドポイントを登録する
	 */
	public function register_stream_endpoint() {
		register_rest_route( 'feas-ai/v1', '/stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'stream_handler' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * REST APIのリクエストを処理するストリームハンドラ
	 * @param WP_REST_Request $request
	 */
	public function stream_handler( $request ) {
		// ヘッダーやバッファリング無効化
		@ini_set('zlib.output_compression', 0);
		header('X-Accel-Buffering: no');
		while (ob_get_level() > 0) { ob_end_flush(); }
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');

		// REST API用のNonce検証
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			echo "data: " . json_encode(['error' => 'Nonce verification failed.']) . "\n\n";
			flush();
			return;
		}

		// パラメータの取得
		$question = sanitize_text_field( $request->get_param( 'question' ) );
		$history = json_decode( stripslashes( $request->get_param( 'history' ) ?? '[]' ), true );
		if ( empty( $question ) ) { return; }

		// 1. 高速なローカル検索で関連情報を探す
		$similar_chunks = $this->find_similar_chunks( $question );

		// 2. 検索結果の有無を判断し、メタ情報を送信
		$context_found = ! empty( $similar_chunks );
		echo "data: " . json_encode(['meta' => ['context_found' => $context_found]]) . "\n\n";
		flush();

		// 3. 取得した情報と会話履歴を元に、一度だけAIを呼び出して回答を生成
		$this->stream_chat_completion( $question, $similar_chunks, $history );
	}

	/**
	 * ユーザーへの最終的な回答をストリーミングで生成・出力する
	 */
	public function stream_chat_completion( $question, $context_chunks, $history = array() ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) {
			echo "data: " . json_encode(['error' => 'APIキーが設定されていません。']) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$messages = array();
		$context_str = '';

		$system_prompt = "あなたは、提供された「サイト内情報」と「会話履歴」を元に、ユーザーの質問に回答する、高度なAIアシスタントです。

		## 厳守すべきルール
		1.  **必須4項目**: 以下の4項目は、情報が見つからない場合でも、項目名自体は**必ず**出力してください。
			- 【仕事内容】
			- 【雇用形態】
			- 【勤務地】
			- 【給与】
		2.  **不明時の処理**: 上記4項目の情報が文中に見つからない場合は、必ず「**不明**」という単語を記載してください。（例：【給与】不明）
		3.  **会話**: 回答の冒頭には「〜に関するお仕事がX件見つかりました。」のような導入文を、末尾には「他に何かお探しですか？」のような結びの言葉を加えてください。
		4.  **禁止事項**: 文脈にない情報の創作や、事実に基づかない推測は絶対にしないでください。

		## 回答フォーマット
		- 全体をMarkdown形式で記述してください。
		- 各求人のタイトル（【募集要項】など）を`**`（太字）で示してください。
		- 箇条書きのリスト（`-`）を使用してください。
		- 各記事の要約の直後に、情報元となるURL自体を記載してください。";

		if ( ! empty( $context_chunks ) ) {
			$grouped_chunks = array();
			foreach ( $context_chunks as $chunk ) { $grouped_chunks[ $chunk['permalink'] ][] = $chunk['content_chunk']; }
			foreach ( $grouped_chunks as $permalink => $chunks ) {
				$context_str .= "--- 記事ここから ---\n情報元URL: " . $permalink . "\n内容:\n" . implode( "\n", $chunks ) . "\n--- 記事ここまで ---\n\n";
			}
		}

		$messages[] = array('role' => 'system', 'content' => $system_prompt);
		if( ! empty( $history ) ) {
			foreach( $history as $message ) {
				$messages[] = $message;
			}
		}

		$last_user_message = "サイト内情報:\n" . $context_str . "\n\n上記の情報を元に、次の質問に答えてください:\n" . $question;
		$messages[] = array('role' => 'user', 'content' => $last_user_message);

		$api_url = 'https://api.openai.com/v1/chat/completions';
		$body = array(
			'model' => 'gpt-4o',
			'messages' => $messages,
			'temperature' => 0.5,
			'stream' => true,
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $api_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $api_key) );

		// 不完全なデータチャンクを一時的に保持するためのバッファ
		$buffer = '';

		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) use (&$buffer) {
			// 新しいデータをバッファに追加
			$buffer .= $data;

			// バッファ内に完全なSSEメッセージ（\n\nで終わる）があるか探す
			while ( ( $pos = strpos( $buffer, "\n\n" ) ) !== false ) {
				// 完全なメッセージを切り出す
				$message = substr( $buffer, 0, $pos );
				// バッファから切り出したメッセージを削除
				$buffer = substr( $buffer, $pos + 2 );

				// メッセージを解析してクライアントに送信
				$lines = explode("\n", trim($message));
				foreach ($lines as $line) {
					if (strpos($line, 'data: ') === 0) {
						$json_data = substr($line, 6);
						if ($json_data === '[DONE]') {
							echo "data: [DONE]\n\n";
						} else {
							$chunk = json_decode($json_data, true);
							if (isset($chunk['choices'][0]['delta']['content'])) {
								$content = $chunk['choices'][0]['delta']['content'];
								echo "data: " . json_encode(['text' => $content]) . "\n\n";
							}
						}
					}
				}
			}

			if (ob_get_level() > 0) { ob_flush(); }
			flush();
			return strlen( $data );
		});

		curl_exec( $ch );
		curl_close( $ch );
	}

	public function find_similar_chunks( $question ) {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		$segmenter = new TinySegmenter();
		$keywords = array_unique( $segmenter->segment($question) );
		$valid_keywords = [];
		foreach( $keywords as $keyword ) {
			if( mb_strlen($keyword) > 1 ) {
				$valid_keywords[] = $keyword;
			}
		}

		if ( empty( $valid_keywords ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $valid_keywords ), '%s' ) );
		$sql = "SELECT DISTINCT `vector_id` FROM `{$index_table}` WHERE `keyword` IN ( {$placeholders} ) LIMIT 500";
		$vector_ids = $wpdb->get_col( $wpdb->prepare( $sql, $valid_keywords ) );

		if ( empty( $vector_ids ) ) {
			return array();
		}

		$placeholders_ids = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
		$sql_candidates   = "SELECT `post_id`, `content_chunk`, `vector_data` FROM `{$vectors_table}` WHERE `id` IN ( {$placeholders_ids} )";
		$candidate_rows   = $wpdb->get_results( $wpdb->prepare( $sql_candidates, $vector_ids ) );

		if( empty($candidate_rows) ) {
			return array();
		}

		$question_vector_response = $this->get_embeddings_via_selected_provider( array( $question ) );
		if( is_wp_error($question_vector_response) ){
			return array();
		}
		$question_vector = $question_vector_response['data'][0]['embedding'];

		$similarities = array();
		foreach ( $candidate_rows as $row ) {
			$db_vector = json_decode( $row->vector_data, true );
			if ( ! is_array( $db_vector ) || empty( $db_vector ) ) continue;

			$similarity = $this->calculate_cosine_similarity_php( $question_vector, $db_vector );

			$similarities[] = array(
				'post_id'       => $row->post_id,
				'content_chunk' => $row->content_chunk,
				'similarity'    => $similarity,
				'permalink'     => get_permalink( $row->post_id ),
			);
		}

		usort( $similarities, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		return array_slice( $similarities, 0, 7 );
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

	public function get_embeddings_via_selected_provider( $texts ) {
		$provider = get_option( 'feas_ai_api_provider', 'openai' ); // 'openai'をデフォルトに

		if ( 'google' === $provider ) {
			return $this->get_gemini_embeddings( $texts );
		} else {
			return $this->get_embeddings( $texts ); // OpenAI
		}
	}

	public function get_embeddings( $texts ) {
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
			'timeout'     => 60, // タイムアウトを60秒に設定
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			$error_message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : '不明なAPIエラー';
			return new WP_Error( 'api_error', 'APIエラー: ' . $error_message );
		}

		return $response_body;
	}

	public function get_gemini_embeddings( $texts ) {
		$api_key = get_option( 'feas_ai_google_api_key' ); // Google用のAPIキーを取得
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'Google Cloud APIキーが設定されていません。' );
		}

		// ★ エンドポイントが異なる
		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:batchEmbedContents?key=' . $api_key;

		// ★ リクエストボディの構造が異なる
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
			'headers' => array(
				'Content-Type'  => 'application/json',
				// ★ ヘッダーの形式が異なる
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
			$error_message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : '不明なAPIエラー';
			return new WP_Error( 'api_error', 'APIエラー: ' . $error_message );
		}

		// ★ レスポンスからベクトルデータを取り出す構造が異なる
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

	public function ajax_log_query() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

		global $wpdb;
		$logs_table = $wpdb->prefix . 'feas_ai_logs';

		$question = isset( $_POST['question'] ) ? sanitize_text_field( $_POST['question'] ) : '';
		$answer = isset( $_POST['answer'] ) ? wp_kses_post( $_POST['answer'] ) : '';
		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( $_POST['session_id'] ) : '';
		$context_found = isset( $_POST['context_found'] ) ? (bool) $_POST['context_found'] : false;

		if ( ! empty( $question ) && ! empty( $session_id ) ) {
			$wpdb->insert(
				$logs_table,
				array(
					'session_id'    => $session_id,
					'question'      => $question,
					'answer'        => $answer,
					'context_found' => $context_found,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}

		wp_send_json_success();
	}

	/**
	 * 同期プロセスの開始をハンドルする
	 */
	public function ajax_start_sync() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

		// 古いデータをすべて削除
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		$wpdb->query( "TRUNCATE TABLE `{$index_table}`" );

		// 処理すべき投稿の総数を計算
		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 1000,
			'fields'         => 'ids', // IDだけ取得すれば高速
		);
		$all_post_ids = get_posts( $args );

		// 総ページ数を計算してJSに返す
		$batch_size = 10;
		$total_posts = count($all_post_ids);
		$total_pages = ceil( $total_posts / $batch_size );

		wp_send_json_success( array(
			'total_pages' => $total_pages,
			'total_posts' => $total_posts,
		) );
	}

	/**
	 * 1バッチ分の同期処理をハンドルする
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$batch_size = 10;

		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		$posts_batch = get_posts( $args );

		if ( empty( $posts_batch ) ) {
			wp_send_json_success( array('message' => 'No more posts to process.') );
			return;
		}

		$segmenter = new TinySegmenter();
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		foreach ( $posts_batch as $post ) {
			$content = strip_shortcodes( $post->post_content );
			$content = wp_strip_all_tags( $content );
			$raw_chunks = explode( "\n\n", $content );
			$valid_chunks = array_values( array_filter( array_map( 'trim', $raw_chunks ) ) );

			if ( empty( $valid_chunks ) ) { continue; }

			$embedding_response = $this->get_embeddings_via_selected_provider( $valid_chunks );

			if ( ! is_wp_error( $embedding_response ) ) {
				$vectors_data = $embedding_response['data'];
				foreach ( $vectors_data as $index => $vector_item ) {
					$wpdb->insert(
						$vectors_table,
						array(
							'post_id'       => $post->ID,
							'chunk_index'   => $index,
							'content_chunk' => $valid_chunks[$index],
							'vector_data'   => json_encode( $vector_item['embedding'] ),
							'created_at'    => current_time( 'mysql' ),
						),
						// ▼▼▼ 抜けていたフォーマット指定子を追加 ▼▼▼
						array( '%d', '%d', '%s', '%s', '%s' )
					);
					$vector_id = $wpdb->insert_id;
					if ($vector_id) {
						$keywords = $segmenter->segment($valid_chunks[$index]);
						foreach ( array_unique( $keywords ) as $keyword ) {
							if ( mb_strlen( $keyword ) > 1 ) {
								$wpdb->insert(
									$index_table,
									array(
										'keyword'   => $keyword,
										'vector_id' => $vector_id,
									),
									// こちらも念のため追加
									array( '%s', '%d' )
								);
							}
						}
					}
				}
			}
		}

		wp_send_json_success( array('message' => 'Batch ' . $page . ' processed.') );
	}

	private function reframe_query_with_history( $question, $history ) {
		if ( empty( $history ) ) {
			return $question; // 履歴がなければ元の質問をそのまま返す
		}

		$system_prompt = "あなたは、会話の文脈を理解し、ユーザーの最新の質問を自己完結した検索クエリに書き換える専門家です。もし質問が既に自己完結している場合は、そのまま質問を返してください。\n\n例:\n履歴:\nuser: 動物を扱う仕事はある？\nassistant: はい、犬の洋服を販売する仕事があります。\nユーザーの最新質問: 勤務先はどこ？\n書き換えたクエリ: 犬の洋服を販売する仕事の勤務地";

		$messages = array(
			array('role' => 'system', 'content' => $system_prompt)
		);
		// 履歴（現在の質問は除く）を追加
		$history_for_prompt = array_slice( $history, 0, -1 );
		foreach( $history_for_prompt as $message ) {
			$messages[] = $message;
		}
		$messages[] = array('role' => 'user', 'content' => $question);

		// 以前作成した、messages配列を扱えるヘルパー関数を呼び出す
		$reframed_question = $this->get_simple_chat_response_with_messages( $messages );

		if ( is_wp_error( $reframed_question ) || empty( $reframed_question ) ) {
			return $question; // エラー時は元の質問を返す
		}

		return $reframed_question;
	}
}
