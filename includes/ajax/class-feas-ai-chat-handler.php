<?php
/**
 * Handles all AJAX and REST API communication for the chat functionality.
 *
 * This file defines the FEAS_AI_Chat_Handler class, which is responsible for
 * registering API endpoints, processing incoming chat requests (streaming and non-streaming),
 * dispatching them to the correct AI model, and handling conversation logging.
 *
 * @package    fe-ai-search
 * @subpackage Ajax
 * @since      1.0.0
 */

namespace FEAISearch\Ajax;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main controller for all frontend chat interactions.
 *
 * This class sets up the necessary endpoints and callbacks to handle
 * real-time streaming chat, non-streaming API queries, conversation logging,
 * and API key validation tests.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Chat_Handler {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( 'wp_ajax_nopriv_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_test_api_key', array( $this, 'ajax_test_api_key' ) );
	}

	/**
	 * Register REST API endpoints for streaming and non-streaming
	 */
	public function register_endpoints() {
		// For website chat (streaming)
		register_rest_route( 'feas-ai/v1', '/stream', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'stream_handler' ],
			'permission_callback' => '__return_true',
		] );

		// For headless CMS and external applications (non-streaming)
		register_rest_route('feas-ai/v1', '/query', [
			'methods'  => 'POST',
			'callback' => [$this, 'query_handler'],
			'permission_callback' => [$this, 'can_access_query_api'],
		]);
	}

	/**
	 * Stream handler for processing REST API requests
	 */
	public function stream_handler( $request ) {
		@ini_set('zlib.output_compression', 0);
		header('X-Accel-Buffering: no');
		while (ob_get_level() > 0) { ob_end_flush(); }
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			echo "data: " . json_encode(['error' => 'Nonce verification failed.']) . "\n\n";
			flush();
			return;
		}

		$question = sanitize_text_field( $request->get_param( 'question' ) );
		$history = json_decode( stripslashes( $request->get_param( 'history' ) ?? '[]' ), true );
		if ( empty( $question ) ) { return; }

		$similar_chunks = $GLOBALS['feas_ai_sync_handler']->find_similar_chunks( $question );

		$context_found = ! empty( $similar_chunks );
		echo "data: " . json_encode(['meta' => ['context_found' => $context_found]]) . "\n\n";
		flush();

		$this->stream_chat_completion( $question, $similar_chunks, $history );
	}

	/**
	 * Command tower for switching response-generating AI
	 */
	public function stream_chat_completion( $question, $context_chunks, $history = array() ) {
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );

		if ( 'google' === $provider ) {
			$this->stream_completion_for_gemini( $question, $context_chunks, $history );
		} elseif ( 'anthropic' === $provider ) {
			$this->stream_completion_for_claude( $question, $context_chunks, $history );
		} else {
			$this->stream_completion_for_openai( $question, $context_chunks, $history );
		}
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

	/**
	 * /query APIのアクセス権限をチェックする
	 */
	public function can_access_query_api( $request ) {
		$headers = $request->get_headers();
		$auth_header = $headers['authorization'][0] ?? '';
		$token = str_replace('Bearer ', '', $auth_header);

		$saved_token = get_option('feas_ai_api_token'); // 将来的に、トークン管理機能を追加
		if ( ! $saved_token || ! hash_equals( $saved_token, $token ) ) {
			return new WP_Error( 'rest_forbidden', 'Invalid API token.', array( 'status' => 401 ) );
		}
		return true;
	}


	/**
	 * /query APIのリクエストを処理するハンドラ
	 */
	public function query_handler( $request ) {
		$question = sanitize_text_field($request->get_param('question'));
		$history = json_decode(stripslashes($request->get_param('history') ?? '[]'), true);
		if (empty($question)) {
			return new WP_REST_Response(['error' => 'Question is empty.'], 400);
		}

		$similar_chunks = $GLOBALS['feas_ai_sync_handler']->find_similar_chunks($question);
		$context_found = !empty($similar_chunks);

		$answer_text = $this->get_non_streaming_completion($question, $similar_chunks, $history);

		$response_data = [
			'answer' => $answer_text,
			'meta'   => ['context_found' => $context_found],
		];

		return new WP_REST_Response($response_data, 200);
	}

	/**
	 * 非ストリーミングでAIからの回答を取得する司令塔
	 */
	public function get_non_streaming_completion($question, $context_chunks, $history) {
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );

		switch ($provider) {
			case 'anthropic':
				return $this->fetch_completion_for_claude($question, $context_chunks, $history);
			case 'google':
				return $this->fetch_completion_for_gemini($question, $context_chunks, $history);
			case 'openai':
			default:
				return $this->fetch_completion_for_openai($question, $context_chunks, $history);
		}
	}

	/**
	 * OpenAI専用のストリーミング処理
	 */
	private function stream_completion_for_openai( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) {
			echo "data: " . json_encode(['text' => __( 'Error: OpenAI API key not set.', 'fe-ai-search' )]) . "\n\n";
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
	private function stream_completion_for_claude( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		if ( empty( $api_key ) ) {
			echo "data: " . json_encode(['error' => __( 'Error: Anthropic API key is not set.', 'fe-ai-search' )]) . "\n\n";
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

	private function stream_completion_for_gemini($question, $context_chunks, $history) {
		$api_key = get_option('feas_ai_google_api_key');
		if (empty($api_key)) {
			echo "data: " . json_encode(['text' => __( 'Error: Google API key not set.', 'fe-ai-search' )]) . "\n\n";
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

	/**
	 * OpenAIから1回で回答を取得する
	 */
	private function fetch_completion_for_openai($question, $context_chunks, $history) {
		$api_key = get_option('feas_ai_openai_api_key');
		if (empty($api_key)) return 'Error: API key not set.';

		$messages = $this->build_prompt_messages($question, $context_chunks, $history, false);
		$body = [ 'model' => 'gpt-4o', 'messages' => $messages, 'temperature' => 0.5 ];

		$response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
			'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ],
			'body' => wp_json_encode($body), 'timeout' => 30
		]);

		if (is_wp_error($response)) return 'Error: ' . $response->get_error_message();
		$data = json_decode(wp_remote_retrieve_body($response), true);
		return $data['choices'][0]['message']['content'] ?? '';
	}

	/**
	 * Claudeから1回で回答を取得する
	 */
	private function fetch_completion_for_claude($question, $context_chunks, $history) {
		$api_key = get_option('feas_ai_anthropic_api_key'); // ★ 正しいオプション名に修正
		if (empty($api_key)) return 'Error: Anthropic API key not set.';

		// ★ Claude用に $for_claude = true で呼び出す
		$messages_data = $this->build_prompt_messages($question, $context_chunks, $history, true);

		$body = [
			'model'      => 'claude-3-5-sonnet-20240620',
			'max_tokens' => 4096,
			'messages'   => $messages_data['messages'],
			'system'     => $messages_data['system'], // ★ systemパラメータを追加
		];

		$response = wp_remote_post('https://api.anthropic.com/v1/messages', [
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode($body),
			'timeout' => 30,
		]);

		if (is_wp_error($response)) return 'Error: ' . $response->get_error_message();

		$data = json_decode(wp_remote_retrieve_body($response), true);
		return $data['content'][0]['text'] ?? '';
	}

	/**
	 * Geminiから1回で回答を取得する
	 */
	private function fetch_completion_for_gemini($question, $context_chunks, $history) {
		$api_key = get_option('feas_ai_google_api_key'); // ★ 正しいオプション名に修正
		if (empty($api_key)) return 'Error: Google API key not set.';

		$messages_data = $this->build_prompt_messages($question, $context_chunks, $history, false);
		$system_prompt_data = array_shift($messages_data);
		$system_prompt_text = $system_prompt_data['content'];

		// ★ ストリーミング版と同じ、正しいcontents組み立てロジック
		$gemini_contents = [];
		foreach ($messages_data as $message) {
			$role = ($message['role'] === 'assistant') ? 'model' : 'user';
			if (!empty($gemini_contents) && end($gemini_contents)['role'] === $role) continue;
			$gemini_contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
		}

		$body = [
			'contents' => $gemini_contents,
			'systemInstruction' => [
				'parts' => [['text' => $system_prompt_text]]
			],
		];

		$response = wp_remote_post('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro-latest:generateContent?key=' . $api_key, [
			'headers' => ['Content-Type' => 'application/json'],
			'body'    => wp_json_encode($body),
			'timeout' => 30,
		]);

		if (is_wp_error($response)) return 'Error: ' . $response->get_error_message();

		$data = json_decode(wp_remote_retrieve_body($response), true);
		return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
	}

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

}
