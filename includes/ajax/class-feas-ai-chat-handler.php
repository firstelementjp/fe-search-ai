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
		add_filter( 'feas_ai_filter_user_question', [ $this, 'filter_basic_injection_phrases' ], 20 );
		add_filter( 'feas_ai_filter_model_response', [ $this, 'filter_basic_injection_phrases' ], 20 );
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
		// Disable server buffering
		@ini_set('output_buffering', 'off');
		@ini_set('zlib.output_compression', 0);
		@ini_set('implicit_flush', 1);
		ob_implicit_flush(1);

		// Clear all existing output buffers.
		while (ob_get_level() > 0) {
			ob_end_flush();
		}

		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no'); // Nginx

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
			exit;
		}
	}

	/**
	 * Command tower for switching response-generating AI
	 */
	public function stream_chat_completion( $question, $context_chunks, $history = array(), $provider ) {

		/**
		 * Fires before the default stream completion handlers.
		 * Allows Pro add-on or other plugins to implement their own handlers.
		 * The hooked function should call exit() to prevent default execution.
		 *
		 * @param string $question       User's question.
		 * @param array  $context_chunks Relevant context chunks.
		 * @param array  $history        Conversation history.
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
			default:
				$this->stream_completion_for_openai( $question, $context_chunks, $history );
				break;
		}
	}

	/**
	 * Streaming processing dedicated to OpenAI
	 */
	private function stream_completion_for_openai( $question, $context_chunks, $history ) {
		if ( 'openai_compatible' === $provider ) {
			$api_url = get_option( 'feas_ai_openai_compatible_endpoint' );
			$api_key = get_option( 'feas_ai_openai_api_key' );
		} else {
			$api_url = 'https://api.openai.com/v1/chat/completions';
			$api_key = get_option( 'feas_ai_openai_api_key' );
		}

		if ( empty( $api_key ) || empty($api_url) ) {
			echo "data: " . json_encode(['text' => __( 'Error: OpenAI API key not set.', 'fe-ai-search' )]) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$model = 'gpt-4o-mini'; // Default
		if ( class_exists('FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings') ) {
			$model = get_option( 'feas_ai_openai_model', 'gpt-4o-mini' );
		}

		$messages = $this->build_prompt_messages( $question, $context_chunks, $history, false );

		$body = [
			'model'    => $model,
			'messages' => $messages,
			'stream'   => true,
		];
		$ch = curl_init();

		curl_setopt( $ch, CURLOPT_URL, $api_url );
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
	 * Streaming processing dedicated to Gemini
	 */
	private function stream_completion_for_gemini( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_google_api_key' );
		if ( empty( $api_key ) ) {
			error_log('Error: Google API key is missing.');
			echo "data: " . json_encode(['text' => __( 'Error: Google API key is not set.', 'fe-ai-search' )]) . "\n\n";
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

		$model = 'gemini-2.5-flash-lite'; // Default
		if ( class_exists('FEAS_AI_Pro') ) {
			$model = get_option( 'feas_ai_google_model', 'gemini-2.5-flash-lite' );
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
	 * Streaming processing dedicated to Claude
	 */
	private function stream_completion_for_claude( $question, $context_chunks, $history ) {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		if ( empty( $api_key ) ) {
			echo "data: " . json_encode(['text' => __( 'Error: Anthropic API key not set.', 'fe-ai-search' )]) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$model = 'claude-3-5-haiku-20241022'; // Default
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
	 * Common function to execute cURL stream and transfer the results to the client.
	 */
	// private function execute_stream_and_forward( $ch, $provider ) {
	// 	curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) use ( $provider ) {
	// 		// Split raw data from AI by rows
	// 		$lines = explode("\n", $data);
//
	// 		foreach ($lines as $line) {
	// 			if (strpos($line, 'data: ') === 0) {
	// 				$json_data = substr($line, 6);
	// 				if (trim($json_data) === '[DONE]') {
	// 					continue;
	// 				}
//
	// 				$chunk = json_decode($json_data, true);
	// 				$content = '';
//
	// 				// Extract text according to the format of each provider.
	// 				if ('openai' === $provider && isset($chunk['choices'][0]['delta']['content'])) {
	// 					$content = $chunk['choices'][0]['delta']['content'];
	// 				} elseif ('anthropic' === $provider && isset($chunk['delta']['type']) && 'text_delta' === $chunk['delta']['type']) {
	// 					$content = $chunk['delta']['text'];
	// 				} elseif ('google' === $provider && isset($chunk['candidates'][0]['content']['parts'][0]['text'])) {
	// 					$content = $chunk['candidates'][0]['content']['parts'][0]['text'];
	// 				}
//
	// 				if ( ! empty( $content ) ) {
	// 					$content = apply_filters( 'feas_ai_filter_model_response', $content );
	// 					echo "data: " . json_encode(['text' => $content]) . "\n\n";
	// 					if (ob_get_level() > 0) { ob_flush(); }
	// 					flush();
	// 				}
	// 			}
	// 		}
	// 		return strlen( $data );
	// 	});
//
	// 	curl_exec( $ch );
	// 	echo "data: [DONE]\n\n";
	// 	flush();
	// 	curl_close( $ch );
	// }

	/**
	 * A common function to assemble prompts and messages array
	 */
	public static function build_prompt_messages( $question, $context_chunks, $history, $for_claude = false ) {
		$context_str = '';
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );

		// Get the default prompt
		$system_prompt = self::get_default_system_prompt();

		// If there is a basic prompt, overwrite with it.
		$free_prompt = get_option( 'feas_ai_system_prompt' );
		if ( ! empty( $free_prompt ) ) {
			$system_prompt = $free_prompt;
		}

		// If the Pro version is enabled and there are model-specific settings, they will overwrite accordingly.
		if ( class_exists('FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings') ) {
			$custom_prompts = get_option( 'feas_ai_custom_prompts', [] );
			if ( ! empty( $custom_prompts[$provider] ) ) {
				$system_prompt = $custom_prompts[$provider];
			}
		}

		/**
		 * Filters the final system prompt before it is sent to the AI.
		 *
		 * This hook allows developers to programmatically modify the system prompt
		 * to change the AI's behavior, add rules, or tailor its personality.
		 *
		 * @since 1.0.0
		 *
		 * @param string $system_prompt The system prompt string to be sent to the AI model.
		 * @param string $provider      The slug of the current AI provider (e.g., 'openai', 'google').
		 */
		$system_prompt = apply_filters( 'feas_ai_system_prompt', $system_prompt, $provider );

		// Construct a context string
		if ( ! empty( $context_chunks ) ) {
			$context_str = "Site Information:\n";
			foreach ( $context_chunks as $chunk ) {
				$context_str .= "--- START OF ARTICLE ---\n" .
								"Source URL: " . $chunk['permalink'] . "\n" .
								"Content:\n" . $chunk['content_chunk'] . "\n" .
								"--- END OF ARTICLE ---\n\n";
			}
		}

		/**
		 * Filter the conversation history to remove empty assistant messages.
		 *
		 * During streaming, an empty 'assistant' message might be added as a
		 * placeholder. This cleanup ensures that only messages with actual
		 * content are sent back to the AI in subsequent requests.
		 *
		 * @var array $messages The cleaned conversation history.
		 */
		$messages = array_filter($history, function($msg){
			return !(isset($msg['role']) && $msg['role'] === 'assistant' && empty(trim($msg['content'])));
		});

		$user_question_with_context = $context_str . "\n\nBased on the Site Information provided above, answer the following question:\n" . $question;

		$messages[] = [
			'role'    => 'user',
			'content' => $user_question_with_context,
		];

		// Return the final data in the format specified by the provider.
		if ( $for_claude ) {
			return ['messages' => array_values($messages), 'system' => $system_prompt];
		} else { // for OpenAI & Gemini
			array_unshift( $messages, ['role' => 'system', 'content' => $system_prompt] );
			return $messages;
		}
	}

	/**
	 * Returns the default system prompt.
	 * @return string
	 */
	public static function get_default_system_prompt() {
		return "You are a kind and excellent AI assistant that answers questions about this website. Based on the provided \"Site Information\" and \"Conversation History,\" please answer by strictly following the rules below.

## Thought Process
1. Accurately understand the intent of the user's latest question.
2. Read the \"Site Information\" and identify the parts most relevant to the question.
3. If no relevant information is found in the \"Site Information,\" honestly state, \"I could not find an answer to your question from the information on this site.\"
4. If relevant information is available, generate a clear and concise summary based solely on that information.

## Strict Rules
- **Cite Sources**: If you use \"Site Information\" in your answer, you must include the source URL at the end of the response.
- **Fact-Based Answers**: Do not include information that is not written in the \"Site Information,\" your general knowledge, or speculation in your answers.
- **Honesty**: Do not invent information or pretend to know something you don't.
- **Conversation**: If the \"Site Information\" is empty and the user is making a greeting or engaging in general conversation, respond naturally as the site's assistant.
- **Formatting**: Format your answers using standard Markdown, such as headings and lists, to make them easy to read.";
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
			wp_send_json_error( __( 'Provider or API key missing.', 'fe-ai-search' ) );
		}

		$result = null;

		/**
		 * Allows add-ons to handle API key tests for custom providers.
		 * The hooked function should return an array ['is_valid' => bool, 'message' => string]
		 * or null to proceed to default handlers.
		 *
		 * @param array|null $result   The result array or null.
		 * @param string     $provider The provider slug being tested.
		 * @param string     $api_key  The API key being tested.
		 */
		$result = apply_filters( 'feas_ai_handle_custom_api_test', $result, $provider, $api_key );

		if ( null === $result ) {
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
						$error_message = is_wp_error($response) ? $response->get_error_message() : __( 'Authentication failed.', 'fe-ai-search' );
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
							'model' => 'claude-3-haiku-20240307',
							'max_tokens' => 1,
							'messages' => [['role' => 'user', 'content' => 'Hello']]
						])
					]);
					// Claude may return 200 even for invalid keys, so we determine this by the error type in the body.
					if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
						$error_message = __( '401 Unauthorized', 'fe-ai-search' );
					} else {
						$is_valid = true; // Any response other than 401 is likely to mean the key is valid
					}
					break;

				case 'google':
					// Call a free API to get a list of models
					$api_url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
					$response = wp_remote_get($api_url);

					if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
						$is_valid = true;
					} else {
						$error_message =  __( 'Authentication failed.', 'fe-ai-search' );
						if (!is_wp_error($response)) {
							$body = json_decode(wp_remote_retrieve_body($response), true);
							$error_message = $body['error']['message'] ?? $error_message;
						}
					}
					break;

				case 'deepseek':
					$response = wp_remote_get('https://api.deepseek.com/models', [
						'headers' => ['Authorization' => 'Bearer ' . $api_key]
					]);
					if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
						$is_valid = true;
					} else {
						$error_message = is_wp_error($response) ? $response->get_error_message() : __( 'Authentication failed.', 'fe-ai-search' );
					}
					break;
			}
			$result = ['is_valid' => $is_valid, 'message' => $error_message];
		}

		if ( $result['is_valid'] ) {
			wp_send_json_success('<span style="color: green;">✔ ' . __( 'Connection successful', 'fe-ai-search' ) . '</span>' );
		} else {
			wp_send_json_error('<span style="color: red;">✖ ' . __( 'Connection failed', 'fe-ai-search' ) . ': ' . $result['message'] . '</span>');
		}
	}

	/**
	 * Filter basic prompt injection attack phrases
	 *
	 * @param string $text The text to filter.
	 * @return string The filtered text.
	 */
	public function filter_basic_injection_phrases( $text ) {
		$locale = get_locale();
		$lang_code = strstr( $locale, '_', true ) ?: $locale;
		$injection_phrases = [];

		// Find the language file and load the anti-injection phrases
		$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$locale}.php";
		if ( ! file_exists( $lang_file ) ) {
			$lang_file = FEAS_AI_PLUGIN_DIR . "includes/i18n/{$lang_code}.php";
		}
		if ( file_exists( $lang_file ) ) {
			$lang_data = include( $lang_file );
			if ( ! empty( $lang_data['injection_phrases'] ) ) {
				$injection_phrases = $lang_data['injection_phrases'];
			}
		}

		if ( empty( $injection_phrases ) ) {
			return $text;
		}

		return str_ireplace( $injection_phrases,  __( '[REDACTED]', 'fe-ai-search' ), $text );
	}

}
