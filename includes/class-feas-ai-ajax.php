<?php

use U7aro\TinySegmenter\TinySegmenter;

class FEAS_AI_Ajax {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_stream_endpoint' ) );
		add_action( 'wp_ajax_nopriv_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_start_sync', array( $this, 'ajax_start_sync' ) );
		add_action( 'wp_ajax_feas_ai_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_feas_ai_test_api_key', array( $this, 'ajax_test_api_key' ) );
		add_action( 'wp_ajax_feas_ai_update_sync_timestamp', array( $this, 'ajax_update_sync_timestamp' ) );
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
	 * REST APIのリクエストを処理するストリームハンドラ（最終安定版）
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
	 * 回答生成AIを切り替える司令塔
	 */
	public function stream_chat_completion( $question, $context_chunks, $history = array() ) {
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );

		if ( 'google' === $provider ) {
			$this->stream_gemini_completion( $question, $context_chunks, $history );
		} elseif ( 'anthropic' === $provider ) {
			$this->stream_claude_completion( $question, $context_chunks, $history );
		} else {
			$this->stream_openai_completion( $question, $context_chunks, $history );
		}
	}

	/**
	 * OpenAI専用のストリーミング処理
	 */
	private function stream_openai_completion( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) {
			echo "data: " . json_encode(['text' => 'エラー: OpenAI APIキーが設定されていません。']) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$messages = $this->build_prompt_messages( $question, $context_chunks, $history, false );

		$api_url = 'https://api.openai.com/v1/chat/completions';
		$body = array(
			'model'       => 'gpt-4o',
			'messages'    => $messages,
			'temperature' => 0.5,
			'stream'      => true,
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $api_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $api_key) );

		$this->execute_stream_and_forward( $ch, 'openai' );
	}

	/**
	 * Claude専用のストリーミング処理
	 */
	private function stream_claude_completion( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		if ( empty( $api_key ) ) {
			echo "data: " . json_encode(['error' => 'Anthropic APIキーが設定されていません。']) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		// ★ 共通ヘルパー関数を呼び出して、プロンプトとmessages配列を一度に生成
		$messages_data = $this->build_prompt_messages( $question, $context_chunks, $history, true ); // trueはClaude用を示す

		$api_url = 'https://api.anthropic.com/v1/messages';
		$body = array(
			'model'      => 'claude-3-5-sonnet-20240620',
			'max_tokens' => 4096,
			'messages'   => $messages_data['messages'],
			'system'     => $messages_data['system'],
			'stream'     => true,
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $api_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'x-api-key: ' . $api_key,
			'anthropic-version: 2023-06-01'
		) );

		// 安定化されたストリーミング処理 (変更なし)
		$buffer = '';
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) use (&$buffer) {
			$buffer .= $data;
			while ( ( $pos = strpos( $buffer, "\n\n" ) ) !== false ) {
				$event_str = substr( $buffer, 0, $pos );
				$buffer = substr( $buffer, $pos + 2 );

				if (strpos($event_str, 'event: content_block_delta') !== false) {
					$json_data = str_replace('data: ', '', substr($event_str, strpos($event_str, 'data: ')));
					$chunk = json_decode($json_data, true);
					if (isset($chunk['delta']['type']) && $chunk['delta']['type'] === 'text_delta') {
						$content = $chunk['delta']['text'];
						echo "data: " . json_encode(['text' => $content]) . "\n\n";
					}
				} elseif (strpos($event_str, 'event: message_stop') !== false) {
						echo "data: [DONE]\n\n";
				}
			}

			if (ob_get_level() > 0) { ob_flush(); }
			flush();
			return strlen( $data );
		});

		curl_exec( $ch );
		curl_close( $ch );
	}


	// private function stream_claude_completion( $question, $context_chunks, $history ) {
	// 	header('Content-Type: text/plain; charset=utf-8');
	// 	echo "--- CLAUDE API DEBUG --- \n\n";
//
	// 	$api_key = get_option( 'feas_ai_anthropic_api_key' );
	// 	if ( empty( $api_key ) ) {
	// 		wp_die('エラー: Anthropic APIキーが設定されていません。');
	// 	}
//
	// 	// messages配列とsystemプロンプトの組み立て
	// 	$messages = array();
	// 	$context_str = '';
	// 	$system_prompt = "あなたは、提供された「サイト内情報」と「会話履歴」を元に、ユーザーの質問に回答する、高度なAIアシスタントです..."; // (プロンプト全文はあなたのコードのままでOKです)
//
	// 	if ( ! empty( $context_chunks ) ) {
	// 		$grouped_chunks = array();
	// 		foreach ( $context_chunks as $chunk ) { $grouped_chunks[ $chunk['permalink'] ][] = $chunk['content_chunk']; }
	// 		foreach ( $grouped_chunks as $permalink => $chunks ) {
	// 			$context_str .= "--- 記事ここから ---\n情報元URL: " . $permalink . "\n内容:\n" . implode( "\n", $chunks ) . "\n--- 記事ここまで ---\n\n";
	// 		}
	// 	}
//
	// 	// 履歴をフィルタリングして、空のアシスタントメッセージを除外
	// 	$filtered_history = array_filter($history, function($msg){
	// 		return !(isset($msg['role']) && $msg['role'] === 'assistant' && empty(trim($msg['content'])));
	// 	});
//
	// 	$messages = $filtered_history;
	// 	$last_message = array_pop( $messages );
//
	// 	if ($last_message) {
	// 		$last_message['content'] = "サイト内情報:\n" . $context_str . "\n\n上記の情報を元に、次の質問に答えてください:\n" . $last_message['content'];
	// 		$messages[] = $last_message;
	// 	} else {
	// 		$messages[] = array('role' => 'user', 'content' => "サイト内情報:\n" . $context_str . "\n\n上記の情報を元に、次の質問に答えてください:\n" . $question);
	// 	}
//
	// 	echo "## 1. Assembled Messages Array (Sent to API):\n";
	// 	print_r($messages);
	// 	echo "\n\n";
//
	// 	$api_url = 'https://api.anthropic.com/v1/messages';
	// 	$body = array(
	// 		'model'      => 'claude-3-5-sonnet-20240620',
	// 		'max_tokens' => 4096,
	// 		'messages'   => array_values( $messages ),
	// 		'system'     => $system_prompt,
	// 	);
//
	// 	$response = wp_remote_post( $api_url, array(
	// 		'headers' => array(
	// 			'Content-Type'        => 'application/json',
	// 			'x-api-key'           => $api_key,
	// 			'anthropic-version'   => '2023-06-01',
	// 		),
	// 		'body'    => json_encode( $body ),
	// 		'timeout' => 30,
	// 	));
//
	// 	echo "## 2. Full Response from wp_remote_post:\n";
	// 	print_r($response);
	// 	echo "\n\n";
//
	// 	if ( is_wp_error( $response ) ) {
	// 		echo "## 3. WP_Error Details:\n";
	// 		print_r($response->get_error_message());
	// 	} else {
	// 		echo "## 3. Raw Body from Claude API:\n";
	// 		print_r(wp_remote_retrieve_body($response));
	// 	}
//
	// 	exit;
	// }

	/**
	 * cURLストリームを実行し、結果をクライアントに転送する共通関数
	 */
	private function execute_stream_and_forward( $ch, $provider ) {
		$buffer = '';
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) use (&$buffer, $provider) {
			$buffer .= $data;
			while ( ( $pos = strpos( $buffer, "\n\n" ) ) !== false ) {
				$event_str = substr( $buffer, 0, $pos );
				$buffer = substr( $buffer, $pos + 2 );

				if (strpos($event_str, 'data: ') !== 0) { continue; }

				$json_data = substr($event_str, 6);
				if ($json_data === '[DONE]') {
					echo "data: [DONE]\n\n";
					continue;
				}

				$chunk = json_decode($json_data, true);
				$content = '';

				if ($provider === 'openai' && isset($chunk['choices'][0]['delta']['content'])) {
					$content = $chunk['choices'][0]['delta']['content'];
				} elseif ($provider === 'anthropic' && isset($chunk['delta']['type']) && $chunk['delta']['type'] === 'text_delta') {
					$content = $chunk['delta']['text'];
				}

				if ( ! empty( $content ) ) {
					echo "data: " . json_encode(['text' => $content]) . "\n\n";
				}
			}

			if (ob_get_level() > 0) { ob_flush(); }
			flush();
			return strlen( $data );
		});

		curl_exec( $ch );
		curl_close( $ch );
	}

	/**
	 * デフォルトのシステムプロンプトを返す
	 * @return string
	 */
	public static function get_default_system_prompt() {
		return "あなたは、提供された「サイト内情報」と「会話履歴」を元に、ユーザーの質問に回答する、求人情報アシスタントです。あなたの回答は、必ず以下の思考プロセスと厳守すべきルールに従ってください。

		## 思考プロセス
		1.  まず「会話履歴」を読み、ユーザーの最新の質問の**真の意図**を理解する。
		2.  次に「サイト内情報」を読む。もし空なら、ユーザーは挨拶や雑談をしていると判断する。
		3.  ユーザーの意図に**関連する情報だけ**を「サイト内情報」から選び出す。無関係な情報は完全に無視する。
		4.  選び出した情報だけを根拠として、回答を生成する。

		## 厳守すべきルール
		- **役割**: あなたの役割は、情報を抽出し、指定されたフォーマットでリストアップすることです。リスト以外の、自由な文章による解説や補足は最小限にしてください。
		- **書式**: 回答は必ず**標準的なMarkdown形式**を使用し、段落の間には空行を1行だけ入れてください。`nn`のような不自然な改行は絶対に使用しないでください。
		- **必須項目**: サイト内情報から求人情報を要約する場合、必ず以下の4項目を出力してください。情報が見つからない場合は、必ず「不明」と記載してください。
			- 【仕事内容】
			- 【雇用形態】
			- 【勤務地】
			- 【給与】
		- **見出し**: 各求人のタイトルは`###`（H3見出し）で示してください。
		- **URL**: 各求人の要約の直後に、情報元となるURL自体を記載してください。
		- **会話**: 回答の冒頭には「〜に関するお仕事がX件見つかりました。」のような導入文を、末尾には「他に何かお探しですか？」という定型文を**必ず**加えてください。
		- **禁止事項**: サイト内情報に記載のない情報の創作や、事実に基づかない推測は絶対に禁止です。";
	}

	/**
	 * プロンプトとmessages配列を組み立てる共通関数
	 */
	private function build_prompt_messages( $question, $context_chunks, $history, $for_claude = false ) {
		$context_str = '';

		// プロバイダーとカスタムプロンプト設定を取得
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );
		$custom_prompts = get_option( 'feas_ai_custom_prompts', [] );
		$is_pro = class_exists('FEAS_AI_Pro');
		$custom_prompt_for_provider = $custom_prompts[$provider] ?? '';

		// Pro版が有効で、プロバイダー別のカスタムプロンプトがあればそれを使用。なければデフォルト。
		if ( $is_pro && ! empty( $custom_prompt_for_provider ) ) {
			$system_prompt = $custom_prompt_for_provider;
		} else {
			$system_prompt = self::get_default_system_prompt();
		}

		// 2. コンテキスト文字列を組み立て
		if ( ! empty( $context_chunks ) ) {
			$grouped_chunks = array();
			foreach ( $context_chunks as $chunk ) { $grouped_chunks[ $chunk['permalink'] ][] = $chunk['content_chunk']; }
			foreach ( $grouped_chunks as $permalink => $chunks ) {
				$context_str .= "--- 記事ここから ---\n情報元URL: " . $permalink . "\n内容:\n" . implode( "\n", $chunks ) . "\n--- 記事ここまで ---\n\n";
			}
		}

		// 3. 履歴をフィルタリング
		$messages = array_filter($history, function($msg){
			return !(isset($msg['role']) && $msg['role'] === 'assistant' && empty(trim($msg['content'])));
		});

		// 4. 最後のユーザーメッセージにコンテキストを付加
		$last_message = array_pop( $messages );
		if ( $last_message && $last_message['role'] === 'user' ) {
			$last_message['content'] = "サイト内情報:\n" . $context_str . "\n\n上記の情報を元に、次の質問に答えてください:\n" . $last_message['content'];
			$messages[] = $last_message;
		} else {
			if ($last_message) { $messages[] = $last_message; } // 予期せぬ場合は元に戻す
			$messages[] = array('role' => 'user', 'content' => "サイト内情報:\n" . $context_str . "\n\n上記の情報を元に、次の質問に答えてください:\n" . $question);
		}

		// 5. プロバイダーの形式に合わせて最終的なデータを返す
		if ( $for_claude ) {
			return ['messages' => array_values($messages), 'system' => $system_prompt];
		} else { // for OpenAI
			array_unshift( $messages, array('role' => 'system', 'content' => $system_prompt) );
			return $messages;
		}
	}


	/**
	 * タイムアウトしない、ローカル完結の高速検索メソッド
	 */
	public function find_similar_chunks( $question ) {
		global $wpdb;
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';

		$segmenter = new TinySegmenter();
		$keywords = array_unique( $segmenter->segment($question) );
		$valid_keywords = array_filter($keywords, function($kw){ return mb_strlen($kw) > 1; });

		if ( empty( $valid_keywords ) ) return array();

		$placeholders = implode( ', ', array_fill( 0, count( $valid_keywords ), '%s' ) );
		$sql = "SELECT DISTINCT `vector_id` FROM `{$index_table}` WHERE `keyword` IN ( {$placeholders} ) LIMIT 500";
		$vector_ids = $wpdb->get_col( $wpdb->prepare( $sql, $valid_keywords ) );

		if ( empty( $vector_ids ) ) return array();

		$placeholders_ids = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );
		$sql_candidates   = "SELECT `post_id`, `content_chunk`, `vector_data` FROM `{$vectors_table}` WHERE `id` IN ( {$placeholders_ids} )";
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
		// ★ 回答用ではなく、ベクトル化用の設定を読み込む
		$provider = get_option( 'feas_ai_embedding_provider', 'openai' );

		if ( 'google' === $provider ) {
			return $this->get_gemini_embeddings( $texts );
		} else { // 'openai' or not-supported 'anthropic'
			return $this->get_embeddings( $texts );
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
		if ( ! get_option( 'feas_ai_enable_logging' ) ) {
			wp_send_json_success();
			return;
		}

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

		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
		$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
		$wpdb->query( "TRUNCATE TABLE `{$index_table}`" );

		$post_types_to_sync = get_option( 'feas_ai_sync_post_types', array('post', 'page') );
		$include_ids_str    = get_option( 'feas_ai_include_post_ids', '' );
		$exclude_ids_str    = get_option( 'feas_ai_exclude_post_ids', '' );
		$sync_limit         = (int) get_option( 'feas_ai_sync_limit', 0 );

		// 基本となるクエリ引数を設定
		$args = array(
			'post_status'    => 'publish',
			'posts_per_page' => -1, // ★★★ -1に修正して全件を対象にする
			'fields'         => 'ids',
			'cache_results'  => false, // キャッシュを無効化してメモリを節約
		);

		if ( $sync_limit > 0 ) {
			$args['posts_per_page'] = $sync_limit;
		} else {
			$args['posts_per_page'] = -1; // 0の場合は全件取得
		}

		// ID指定のロジックを適用
		$include_ids = array_filter( array_map('intval', explode(',', $include_ids_str)) );
		$exclude_ids = array_filter( array_map('intval', explode(',', $exclude_ids_str)) );

		if ( ! empty( $include_ids ) ) {
			$args['post__in'] = $include_ids;
		} else {
			$args['post_type'] = empty($post_types_to_sync) ? array('post', 'page') : $post_types_to_sync;
			if ( ! empty( $exclude_ids ) ) {
				$args['post__not_in'] = $exclude_ids;
			}
		}

		// WP_Queryを使って投稿の総数を安全に取得
		$query = new WP_Query( $args );
		$total_posts = $query->found_posts;

		// 総ページ数を計算してJSに返す
		$batch_size = 10; // バッチサイズは100件が効率的
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

		$post_types_to_sync = get_option( 'feas_ai_sync_post_types', array('post', 'page') );
		$include_ids_str    = get_option( 'feas_ai_include_post_ids', '' );
		$exclude_ids_str    = get_option( 'feas_ai_exclude_post_ids', '' );

		if ( empty($post_types_to_sync) ) {
			wp_send_json_success( array('message' => 'No post types selected.') );
			return;
		}

		$args = array(
			'post_type'      => $post_types_to_sync,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$include_ids = array_map('intval', explode(',', $include_ids_str));
		$exclude_ids = array_map('intval', explode(',', $exclude_ids_str));

		if ( ! empty( array_filter($include_ids) ) ) {
			$args['post__in'] = $include_ids;
			// post__in を使う場合、pagedは機能しないため、手動でページングする
			$offset = ($page - 1) * $batch_size;
			$args['offset'] = $offset;
			$args['posts_per_page'] = $batch_size;
			unset($args['paged']); // pagedとoffsetは同時に使えない
		} else {
			$args['post_type'] = empty($post_types_to_sync) ? array('post', 'page') : $post_types_to_sync;
			if ( ! empty( array_filter($exclude_ids) ) ) {
				$args['post__not_in'] = $exclude_ids;
			}
		}

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


	public function get_claude_embeddings( $texts ) {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'Anthropic APIキーが設定されていません。' );
		}

		$api_url = 'https://api.anthropic.com/v1/embeddings'; // (注: 2025年9月時点ではClaudeに専用Embedding APIはありません。これは将来的な実装を見越したダミーです)

		// 現状ClaudeにはEmbedding APIがないため、ダミーのエラーを返す
		return new WP_Error('not_implemented', 'ClaudeのEmbedding機能は現在サポートされていません。OpenAIまたはGeminiを選択してください。');

		// 以下は、もしAPIが存在した場合の実装例
		/*
		$response = wp_remote_post( $api_url, ... );
		...
		return $formatted_response;
		*/
	}

	public function ajax_test_api_key() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );
		$provider = isset($_POST['provider']) ? sanitize_key($_POST['provider']) : '';
		$api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

		if ( empty($provider) || empty($api_key) ) {
			wp_send_json_error('プロバイダーまたはAPIキーがありません。');
		}

		$is_valid = false;
		$error_message = '';

		switch ($provider) {
			case 'openai':
				$response = wp_remote_get('https://api.openai.com/v1/models', [
					'headers' => ['Authorization' => 'Bearer ' . $api_key]
				]);
				if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
					$is_valid = true;
				} else {
					$error_message = is_wp_error($response) ? $response->get_error_message() : '認証に失敗しました。';
				}
				break;

			case 'anthropic':
				$response = wp_remote_post('https://api.anthropic.com/v1/messages', [
					'headers' => [
						'x-api-key' => $api_key,
						'anthropic-version' => '2023-06-01',
						'Content-Type' => 'application/json'
					],
					'body' => json_encode([
						'model' => 'claude-3-haiku-20240307', // 最も軽量なモデルでテスト
						'max_tokens' => 1,
						'messages' => [['role' => 'user', 'content' => 'Hello']]
					])
				]);
				// Claudeは不正なキーでも200を返すことがあるため、ボディのエラータイプで判断
				if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
					 $error_message = '認証に失敗しました (401 Unauthorized)。';
				} else {
					 $is_valid = true; // 401以外の応答はキーが有効である可能性が高い
				}
				break;

			case 'google':
			// モデル一覧を取得する、コストのかからないAPIを叩く
			$api_url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
			$response = wp_remote_get($api_url);

			if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
				$is_valid = true;
			} else {
				$error_message = '認証に失敗しました。';
				if (!is_wp_error($response)) {
					$body = json_decode(wp_remote_retrieve_body($response), true);
					$error_message = $body['error']['message'] ?? $error_message;
				}
			}
			break;
		}

		if ($is_valid) {
			wp_send_json_success('<span style="color: green;">✔ 接続に成功しました</span>');
		} else {
			wp_send_json_error('<span style="color: red;">✖ 接続に失敗しました: ' . $error_message . '</span>');
		}
	}

	public function ajax_update_sync_timestamp() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );
		update_option( 'feas_ai_last_sync_timestamp', current_time( 'timestamp' ) );
		wp_send_json_success();
	}

	// private function stream_gemini_completion( $question, $context_chunks, $history ) {
	// 	header('Content-Type: text/plain; charset=utf-8');
	// 	echo "--- GEMINI API DEBUG (Final Check v2) --- \n\n";
//
	// 	$api_key = get_option( 'feas_ai_google_api_key' );
	// 	if ( empty( $api_key ) ) {
	// 		wp_die('エラー: Google APIキーが設定されていません。');
	// 	}
//
	// 	// 1. 本番と同じロジックでプロンプトとmessages配列を組み立てる
	// 	$messages_data = $this->build_prompt_messages( $question, $context_chunks, $history, false );
	// 	$system_prompt_data = array_shift($messages_data);
	// 	$system_prompt_text = $system_prompt_data['content'];
//
	// 	$gemini_contents = array();
	// 	foreach ($messages_data as $message) {
	// 		$role = ($message['role'] === 'assistant') ? 'model' : 'user';
	// 		if (!empty($gemini_contents) && end($gemini_contents)['role'] === $role) {
	// 			continue;
	// 		}
	// 		$gemini_contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
	// 	}
//
	// 	// 2. APIエンドポイントとリクエストボディを準備 (非ストリーミング用)
	// 	$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-latest:streamGenerateContent?alt=sse&key=' . $api_key;
	// 	$body = array(
	// 		'contents' => $gemini_contents,
	// 		'systemInstruction' => [ 'parts' => [ ['text' => $system_prompt_text] ] ],
	// 	);
//
	// 	echo "## 1. Sending this body to Gemini:\n";
	// 	echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	// 	echo "\n\n";
//
	// 	// 3. wp_remote_postでAPIを呼び出す
	// 	$response = wp_remote_post( $api_url, [
	// 		'headers' => ['Content-Type' => 'application/json'],
	// 		'body'    => json_encode( $body ),
	// 		'timeout' => 30,
	// 	]);
//
	// 	echo "## 2. Full Response from wp_remote_post:\n";
	// 	print_r($response);
	// 	echo "\n\n";
//
	// 	if ( is_wp_error( $response ) ) {
	// 		echo "## 3. WP_Error Details:\n";
	// 		print_r($response->get_error_message());
	// 	} else {
	// 		echo "## 3. Raw Body from Gemini API:\n";
	// 		print_r(wp_remote_retrieve_body($response));
	// 	}
//
	// 	exit;
	// }

	private function stream_gemini_completion($question, $context_chunks, $history) {
		$api_key = get_option('feas_ai_google_api_key');
		if (empty($api_key)) {
			echo "data: " . json_encode(['text' => 'エラー: Google APIキーが設定されていません。']) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$messages_data = $this->build_prompt_messages($question, $context_chunks, $history, false);
		$system_prompt_data = array_shift($messages_data);
		$system_prompt_text = $system_prompt_data['content'];

		$gemini_contents = [];
		foreach ($messages_data as $message) {
			$role = ($message['role'] === 'assistant') ? 'model' : 'user';
			if (!empty($gemini_contents) && end($gemini_contents)['role'] === $role) continue;
			$gemini_contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
		}

		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent?alt=sse&key=' . $api_key;

		$body = [
			'contents' => $gemini_contents,
			'systemInstruction' => [
				'parts' => [['text' => $system_prompt_text]]
			],
		];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

		$buffer = '';
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$buffer) {
			$buffer .= $data;

			// SSEイベントごとに処理
			while (preg_match('/^data: (.*?)(\r?\n\r?\n|$)/s', $buffer, $matches)) {

				$json_str = trim($matches[1]);
				$buffer = substr($buffer, strlen($matches[0]));

				if ($json_str === '[DONE]') continue; // 完了チャンク

				// JSONが不完全な場合は末尾の閉じ中括弧まで切り出す
				$pos = strrpos($json_str, '}');
				if ($pos !== false) {
					$json_str = substr($json_str, 0, $pos + 1);
				}

				$chunk = json_decode($json_str, true);

				if (is_array($chunk)) {
					if (isset($chunk['candidates'][0]['content']['parts'][0]['text'])) {
						$content = $chunk['candidates'][0]['content']['parts'][0]['text'];
						echo "data: " . json_encode(['text' => $content]) . "\n\n";
					}
				} else {
					error_log('Gemini JSON decode error: ' . json_last_error_msg());
					error_log('Problematic JSON: ' . $json_str);
				}
			}

			if (ob_get_level() > 0) ob_flush();
			flush();
			return strlen($data);
		});

		curl_exec($ch);
		curl_close($ch);

		echo "data: [DONE]\n\n";
		flush();
	}


}
