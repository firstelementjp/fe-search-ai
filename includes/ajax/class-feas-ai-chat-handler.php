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
	private $sync_handler;

	public function __construct( $sync_handler ) {
		$this->sync_handler = $sync_handler;

		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( 'wp_ajax_nopriv_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_test_api_key', array( $this, 'ajax_test_api_key' ) );
	}

	/**
	 * Register REST API endpoints for streaming and non-streaming
	 */
	public function register_endpoints() {
		register_rest_route( 'feas-ai/v1', '/stream', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'stream_handler' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Stream handler for processing REST API requests
	 */
	public function stream_handler( $request ) {
		// サーバーのバッファリングを無効化する強力な命令
		@ini_set('output_buffering', 'off');
		@ini_set('zlib.output_compression', 0);
		@ini_set('implicit_flush', 1);
		ob_implicit_flush(1);

		// 既存のすべての出力バッファをクリアする
		while (ob_get_level() > 0) {
			ob_end_flush();
		}

		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no'); // Nginx用

		try {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				throw new \Exception('Nonce verification failed.');
			}

			$provider = get_option( 'feas_ai_chat_provider', 'openai' );

			$question = sanitize_text_field( $request->get_param( 'question' ) );
			$history = json_decode( stripslashes( $request->get_param( 'history' ) ?? '[]' ), true );

			$question = apply_filters( 'feas_ai_filter_user_question', $question );
			if ( empty( $question ) ) { return; }

			$similar_chunks = $this->sync_handler->find_similar_chunks( $question );
			$similar_chunks = apply_filters( 'feas_ai_retrieved_chunks', $similar_chunks, $question );

			$context_found = ! empty( $similar_chunks );
			echo "data: " . json_encode(['meta' => [
				'context_found' => $context_found,
				'provider'      => $provider,
			]]) . "\n\n";
			flush();

			$this->stream_chat_completion( $question, $similar_chunks, $history, $provider );

		} catch (\Exception $e) {
			error_log('FE AI Search Stream Error: ' . $e->getMessage());
			echo "data: " . json_encode(['error' => 'An error occurred during processing.']) . "\n\n";
			flush();
		} finally {
			// 正常・異常に関わらず、ここでスクリプトを完全に終了させる
			exit;
		}
	}

	/**
	 * Command tower for switching response-generating AI
	 */
	public function stream_chat_completion( $question, $context_chunks, $history = array(), $provider ) {
		// $provider = get_option( 'feas_ai_chat_provider', 'openai' );

		/**
		 * 独自のAIプロバイダー処理を割り込ませるためのアクションフック。
		 * フック名: feas_ai_stream_for_{プロバイダーのスラッグ}
		 *
		 * @param string $question       ユーザーの質問.
		 * @param array  $context_chunks 関連情報のチャンク.
		 * @param array  $history        会話履歴.
		 */
		do_action( "feas_ai_stream_for_{$provider}", $question, $context_chunks, $history );

		switch ($provider) {
			case 'google':
				$this->stream_completion_for_gemini( $question, $context_chunks, $history );
				break;
			case 'anthropic':
				$this->stream_completion_for_claude( $question, $context_chunks, $history );
				break;
			case 'openai':
				$this->stream_completion_for_openai( $question, $context_chunks, $history );
				break;
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

		$model = 'gpt-4o-mini'; // Free版のデフォルト
		if ( class_exists('FEAS_AI_Pro') ) {
			$model = get_option( 'feas_ai_openai_model', 'gpt-4o-mini' );
		}

		$messages = $this->build_prompt_messages( $question, $context_chunks, $history, false );

		$body     = [
			'model'    => $model,
			'messages' => $messages,
			'stream'   => true,
		];
		$ch       = curl_init();

		curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json', 'Authorization: Bearer ' . $api_key ] );

		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) {
			$lines = explode("\n", $data);
			foreach ($lines as $line) {
				if (strpos($line, 'data: ') === 0) {
					$json_data = substr($line, 6);
					if (trim($json_data) === '[DONE]') {
						continue;
					}
					$chunk = json_decode($json_data, true);
					if (isset($chunk['choices'][0]['delta']['content'])) {
						$content = apply_filters( 'feas_ai_filter_model_response', $chunk['choices'][0]['delta']['content'] );
						echo "data: " . json_encode(['text' => $content]) . "\n\n";
						if (ob_get_level() > 0) { ob_flush(); }
						flush();
					}
				}
			}
			return strlen($data);
		});
		curl_exec( $ch );
		curl_close( $ch );
	}

	/**
	 * Gemini専用のストリーミング処理
	 */
	private function stream_completion_for_gemini( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_google_api_key' );
		if ( empty( $api_key ) ) {
			error_log('Error: Google API key is missing.');
			echo "data: " . json_encode(['text' => 'エラー: Google APIキーが設定されていません。']) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$messages_data = $this->build_prompt_messages( $question, $context_chunks, $history, false );

		$system_prompt_data = array_shift($messages_data);
		$system_prompt_text = $system_prompt_data['content'];

		$gemini_contents = [];
		foreach ($messages_data as $message) {
			$role = ($message['role'] === 'assistant') ? 'model' : 'user';
			if (!empty($gemini_contents) && end($gemini_contents)['role'] === $role) continue;
			$gemini_contents[] = [
				'role' => $role,
				'parts' => [
						['text' => $message['content']]
				]
			];
		}

		$model = 'gemini-2.5-flash'; // Free版のデフォルト
		if ( class_exists('FEAS_AI_Pro') ) {
			$model = get_option( 'feas_ai_google_model', 'gemini-2.5-flash' );
		}

		$api_url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
			$model,
			$api_key
		);

		$body = [
			'contents' => $gemini_contents,
			'systemInstruction' => [
				'parts' => [
					['text' => $system_prompt_text]
				]
			],
		];

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $api_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json') );
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) {

			$lines = explode("\n", $data);

			foreach ($lines as $line_index => $line) {
				if (strpos($line, 'data: ') === 0) {

					$json_data = substr($line, 6);
					$chunk = json_decode($json_data, true);

					if (is_array($chunk) && isset($chunk['candidates'][0]['content']['parts'][0]['text'])) {

						$content = $chunk['candidates'][0]['content']['parts'][0]['text'];

						/**
						 * Filters a chunk of the AI's response text right before it is sent to the user.
						 *
						 * This hook allows for real-time censorship or modification of the AI's output
						 * as it is being streamed.
						 *
						 * @since 1.0.0
						 *
						 * @param string $content The text chunk generated by the AI model.
						 */
						$content = apply_filters( 'feas_ai_filter_model_response', $content );

						echo "data: " . json_encode(['text' => $content]) . "\n\n";
						if (ob_get_level() > 0) ob_flush();
						flush();
					}
				}
			}
			return strlen($data);
		});

		curl_exec( $ch );
		echo "data: [DONE]\n\n";
		flush();
		curl_close( $ch );
	}

	/**
	 * Claude専用のストリーミング処理
	 */
	private function stream_completion_for_claude( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		if ( empty( $api_key ) ) {
			echo "data: " . json_encode(['text' => __( 'Error: Anthropic API key not set.', 'fe-ai-search' )]) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$model = 'claude-3-5-haiku-20241022'; // Free版のデフォルト
		if ( class_exists('FEAS_AI_Pro') ) {
			$model = get_option( 'feas_ai_anthropic_model', 'claude-3-5-haiku-20241022' );
		}

		$messages_data = $this->build_prompt_messages( $question, $context_chunks, $history, true );

		$body = [
			'model'      => $model,
			'max_tokens' => 4096,
			'messages'   => $messages_data['messages'],
			'system'     => $messages_data['system'],
			'stream'     => true,
		];

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'x-api-key: ' . $api_key,
			'anthropic-version: 2023-06-01'
		] );
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) {
			$lines = explode("\n", $data);

			foreach ($lines as $line) {
				if (strpos($line, 'data: ') === 0) {

					$json_data = substr($line, 6);
					$chunk = json_decode($json_data, true);

					if (isset($chunk['type'])
						&& $chunk['type'] === 'content_block_delta'
						&& isset($chunk['delta']['type']) && $chunk['delta']['type'] === 'text_delta') {

						$content = $chunk['delta']['text'];

						/**
						 * Filters a chunk of the AI's response text right before it is sent to the user.
						 *
						 * This hook allows for real-time censorship or modification of the AI's output
						 * as it is being streamed.
						 *
						 * @since 1.0.0
						 *
						 * @param string $content The text chunk generated by the AI model.
						 */
						$content = apply_filters( 'feas_ai_filter_model_response', $content );

						echo "data: " . json_encode(['text' => $content]) . "\n\n";

						if (ob_get_level() > 0) {
							ob_flush();
						}
						flush();
					}
				}
			}
			return strlen( $data );
		});

		curl_exec( $ch );
		echo "data: [DONE]\n\n";
		flush();
		curl_close( $ch );
	}

	/**
	 * cURLストリームを実行し、結果をクライアントに転送する共通関数
	 */
	private function execute_stream_and_forward( $ch, $provider ) {
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) use ( $provider ) {
			// AIからの生データを行ごとに分割
			$lines = explode("\n", $data);

			foreach ($lines as $line) {
				if (strpos($line, 'data: ') === 0) {
					$json_data = substr($line, 6);
					if (trim($json_data) === '[DONE]') {
						continue;
					}

					$chunk = json_decode($json_data, true);
					$content = '';

					// 各プロバイダーの形式に合わせてテキストを抽出
					if ('openai' === $provider && isset($chunk['choices'][0]['delta']['content'])) {
						$content = $chunk['choices'][0]['delta']['content'];
					} elseif ('anthropic' === $provider && isset($chunk['delta']['type']) && 'text_delta' === $chunk['delta']['type']) {
						$content = $chunk['delta']['text'];
					} elseif ('google' === $provider && isset($chunk['candidates'][0]['content']['parts'][0]['text'])) {
						$content = $chunk['candidates'][0]['content']['parts'][0]['text'];
					}

					if ( ! empty( $content ) ) {
						$content = apply_filters( 'feas_ai_filter_model_response', $content );
						echo "data: " . json_encode(['text' => $content]) . "\n\n";
						if (ob_get_level() > 0) { ob_flush(); }
						flush();
					}
				}
			}
			return strlen( $data );
		});

		curl_exec( $ch );
		echo "data: [DONE]\n\n";
		flush();
		curl_close( $ch );
	}

	/**
	 * プロンプトとmessages配列を組み立てる共通関数
	 */
	private function build_prompt_messages( $question, $context_chunks, $history, $for_claude = false ) {
		$context_str = '';
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );

		// --- 1. プロンプトを階層的に読み込む ---
		// a. まず、デフォルトのプロンプトを取得
		$system_prompt = self::get_default_system_prompt();

		// b. Free版の設定（基本プロンプト）があれば、それで上書き
		$free_prompt = get_option( 'feas_ai_system_prompt' );
		if ( ! empty( $free_prompt ) ) {
			$system_prompt = $free_prompt;
		}

		// c. Pro版が有効で、モデル別設定があれば、さらにそれで上書き
		if ( class_exists('FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings') ) {
			$custom_prompts = get_option( 'feas_ai_custom_prompts', [] );
			if ( ! empty( $custom_prompts[$provider] ) ) {
				$system_prompt = $custom_prompts[$provider];
			}
		}

		// d. 最後に、外部からのフィルターを適用
		$system_prompt = apply_filters( 'feas_ai_system_prompt', $system_prompt, $provider );

		// --- 2. コンテキスト文字列を組み立てる ---
		if ( ! empty( $context_chunks ) ) {
			$context_str = "サイト内情報:\n";
			foreach ( $context_chunks as $chunk ) {
				$context_str .= "--- 記事ここから ---\n情報元URL: " . $chunk['permalink'] . "\n内容:\n" . $chunk['content_chunk'] . "\n--- 記事ここまで ---\n\n";
			}
		}

		// --- 3. 履歴をフィルタリングし、最後の質問にコンテキストを付加 ---
		$messages = array_filter($history, function($msg){
			return !(isset($msg['role']) && $msg['role'] === 'assistant' && empty(trim($msg['content'])));
		});

		$user_question_with_context = $context_str . "\n\n上記の情報を元に、次の質問に答えてください:\n" . $question;

		$messages[] = [
			'role'    => 'user',
			'content' => $user_question_with_context,
		];

		// --- 4. プロバイダーの形式に合わせて最終的なデータを返す ---
		if ( $for_claude ) {
			return ['messages' => array_values($messages), 'system' => $system_prompt];
		} else { // for OpenAI & Gemini
			array_unshift( $messages, ['role' => 'system', 'content' => $system_prompt] );
			return $messages;
		}
	}

	/**
	 * デフォルトのシステムプロンプトを返す
	 * @return string
	 */
	public static function get_default_system_prompt() {
		return "あなたは、このウェブサイトに関する質問に答える、親切で優秀なAIアシスタントです。提供された「サイト内情報」と「会話履歴」を元に、以下のルールを厳守して回答してください。

## 思考プロセス
1. ユーザーの最新の質問の意図を正確に理解します。
2. 「サイト内情報」を読み、質問に最も関連する部分を特定します。
3. もし関連する情報が「サイト内情報」になければ、正直に「サイト内の情報からは、ご質問に関する回答を見つけられませんでした。」と答えます。
4. 関連情報がある場合、その情報だけを根拠として、分かりやすく要約して回答を生成します。

## 厳守すべきルール
- **情報源の明記**: 回答に「サイト内情報」を利用した場合、必ず関連する情報元のURLを回答の末尾に記載してください。
- **事実に基づく回答**: 「サイト内情報」に書かれていないこと、あなたの一般的な知識、推測を回答に含めてはなりません。
- **誠実な態度**: 情報を創作したり、知っているふりをしてはいけません。
- **会話**: 「サイト内情報」が空で、ユーザーが挨拶や一般的な会話をしている場合は、サイトのアシスタントとして自然に応対してください。
- **書式**: 回答は、見出しやリストなど、標準的なMarkdown形式を適切に使用して、読みやすく整形してください。";
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

}
