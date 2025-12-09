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
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main controller for all frontend chat interactions.
 *
 * This class sets up the necessary endpoints and callbacks to handle
 * real-time streaming chat, non-streaming API queries, conversation logging,
 * and API key validation tests.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Ajax
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_Chat_Handler {

	private $options           = [];
	private $is_license_active = '';
	private $sync_handler;

	public function __construct( $sync_handler ) {

		// Retrieve all configuration data
		$this->options = get_option( 'fe_ai_search_settings', [] );

		/**
		 * Check the status of the license (stored in its own option).
		 */
		$license_data = get_option( 'fe_ai_search_license', [] );
		$status       = $license_data['status'] ?? 'inactive';
		$data         = $license_data['data'] ?? [];
		$product_id   = isset( $data['productId'] ) ? (int) $data['productId'] : 0;

		// Treat the license as active when the status is "active" and the product ID
		// matches the Pro add-on (productId = 65 in License Manager for WooCommerce).
		$this->is_license_active = ( 'active' === $status && 65 === $product_id );
		$this->sync_handler      = $sync_handler;

		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );
		add_action( 'wp_ajax_nopriv_fe_ai_search_log_query', [ $this, 'ajax_log_query' ] );
		add_action( 'wp_ajax_fe_ai_search_log_query', [ $this, 'ajax_log_query' ] );
		add_action( 'wp_ajax_nopriv_fe_ai_search_log_consent', [ $this, 'ajax_log_consent' ] );
		add_action( 'wp_ajax_fe_ai_search_log_consent', [ $this, 'ajax_log_consent' ] );
		add_action( 'wp_ajax_fe_ai_search_test_api_key', [ $this, 'ajax_test_api_key' ] );
		add_action( 'wp_ajax_fe_ai_search_manage_license', [ $this, 'ajax_manage_license' ] );
		add_filter( 'fe_ai_search_filter_user_question', [ $this, 'filter_personal_data' ], 10 );
		add_filter( 'fe_ai_search_filter_user_question', [ $this, 'filter_basic_injection_phrases' ], 20 );
		add_filter( 'fe_ai_search_filter_model_response', [ $this, 'filter_basic_injection_phrases' ], 20 );
	}

	/**
	 * Register REST API endpoints for streaming and non-streaming
	 */
	public function register_endpoints() {
		register_rest_route(
			'fe-ai-search/v1',
			'/stream',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'stream_handler' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handles streaming chat completion requests via the REST API.
	 *
	 * Applies per-IP and global rate limiting, validates security nonces,
	 * retrieves relevant context chunks from the sync handler, and then
	 * streams the model's response back to the client using Server-Sent
	 * Events (SSE) compatible output.
	 *
	 * @param \WP_REST_Request $request The incoming REST API request containing
	 *                                  the user's question, chat history, and
	 *                                  provider settings.
	 * @return void Outputs streamed response directly and terminates.
	 */
	public function stream_handler( \WP_REST_Request $request ) {
		$default_limits = [
			'ip_limit_count'     => 50,  // 50 requests per hour per IP (default)
			'global_limit_count' => 1000, // 1000 requests per day for the whole site
			'notify_threshold'   => 80,   // Notify at 80%
			'notify_email'       => get_option( 'admin_email' ), // Default email
		];

		/**
		 * Filters the rate-limiting settings.
		 *
		 * This allows the Pro version or other plugins to override the default rate limits
		 * with values from the settings page.
		 *
		 * @param array $default_limits The default hardcoded limits.
		 */
		$rate_limit_options = apply_filters( 'fe_ai_search_rate_limit_settings', $default_limits );
		$rate_limit_options = wp_parse_args( $rate_limit_options, $default_limits );

		$ip_limit_count     = (int) $rate_limit_options['ip_limit_count'];
		$global_limit_count = (int) $rate_limit_options['global_limit_count'];
		$threshold_percent  = (int) $rate_limit_options['notify_threshold'];
		$notify_email       = $rate_limit_options['notify_email'];

		if ( $ip_limit_count > 0 ) {
			$ip_transient_key = 'fe_ai_search_rl_ip_' . md5( $_SERVER['REMOTE_ADDR'] );
			$ip_request_count = get_transient( $ip_transient_key );

			if ( $ip_request_count > $ip_limit_count ) {
				status_header( 429 );
				echo 'data: ' . json_encode( [ 'text' => __( 'You have exceeded the request limit. Please try again later.', 'fe-ai-search' ) ] ) . "\n\n";
				exit;
			}
			set_transient( $ip_transient_key, ( (int) $ip_request_count + 1 ), HOUR_IN_SECONDS );
		}

		if ( $global_limit_count > 0 ) {
			$global_transient_key = 'fe_ai_search_rl_global_day';
			$global_request_count = get_transient( $global_transient_key );

			if ( $global_request_count > $global_limit_count ) {
				status_header( 429 );
				echo 'data: ' . json_encode( [ 'text' => __( 'The site-wide request limit for today has been reached.', 'fe-ai-search' ) ] ) . "\n\n";
				exit;
			}

			$new_global_count = (int) $global_request_count + 1;
			set_transient( $global_transient_key, $new_global_count, DAY_IN_SECONDS );

			$threshold_count = ( $global_limit_count * $threshold_percent ) / 100;

			if ( $new_global_count > $threshold_count ) {
				if ( ! get_transient( 'fe_ai_search_rl_notify_sent' ) ) {
					$subject = get_bloginfo( 'name' ) . ' - AI Search Limit Warning';
					$message = "Your site's daily AI request limit is about to be reached. "
					. $new_global_count . ' / ' . $global_limit_count . ' requests have been used.';
					wp_mail( $notify_email, $subject, $message ); // Use the final email address
					set_transient( 'fe_ai_search_rl_notify_sent', true, DAY_IN_SECONDS );
				}
			}
		}

		// Disable server buffering
		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'zlib.output_compression', 0 );
		@ini_set( 'implicit_flush', 1 );
		ob_implicit_flush( 1 );

		// Clear all existing output buffers.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Nginx

		$similar_chunks = [];

		try {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				throw new \Exception( 'Nonce verification failed.' );
			}

			// Provider is selected from the provider.chat setting (e.g. openai/google/anthropic).
			$provider = $this->options['provider']['chat'] ?? 'openai';

			$question = sanitize_text_field( $request->get_param( 'question' ) );
			$history  = json_decode( stripslashes( $request->get_param( 'history' ) ?? '[]' ), true );

			/**
			 * Filters the user's question right before it is processed.
			 *
			 * This hook is applied after basic sanitization and allows for custom modifications
			 * to the user's input before it is used for context retrieval or sent to the AI.
			 * This is the final opportunity to filter or modify the question.
			 *
			 * @since 1.0.0
			 *
			 * @param string $question The sanitized user's question.
			 */
			$question = apply_filters( 'fe_ai_search_filter_user_question', $question );
			if ( empty( $question ) ) {
				return;
			}

			// Context retrieval (keyword/vector search)
			try {
				$similar_chunks = $this->sync_handler->find_similar_chunks( $question );

				/**
				 * Filters the array of retrieved context chunks.
				 *
				 * This hook is applied after the database search (both keyword and vector)
				 * is complete, but before the chunks are passed to the AI. It allows for
				 * advanced manipulation of the context, such as re-ranking, filtering,
				 * or adding external data.
				 *
				 * @since 1.0.0
				 *
				 * @param array  $similar_chunks An array of the retrieved chunk items.
				 * @param string $question       The original user's question.
				 */
				$similar_chunks = apply_filters( 'fe_ai_search_retrieved_chunks', $similar_chunks, $question );

			} catch ( \Throwable $t ) {
				// Fail safely: log the error and continue without context.
				\FEAISearch\Core\FE_AI_Search_Logger::log(
					'ERROR',
					'Context retrieval failed in stream_handler.',
					[
						'error_message' => $t->getMessage(),
						'file'          => $t->getFile(),
						'line'          => $t->getLine(),
					]
				);
				$similar_chunks = [];
			}

			$context_found = ! empty( $similar_chunks );

			echo 'data: ' . json_encode(
				[
					'meta' => [
						'context_found' => $context_found,
						'provider'      => $provider,
					],
				]
			) . "\n\n";
			flush();

			$this->stream_chat_completion( $question, $similar_chunks, $history, $provider );

		} catch ( \Exception $e ) {
			echo 'data: ' . json_encode( [ 'error' => 'An error occurred during processing.' ] ) . "\n\n";
			flush();
		} finally {
			exit;
		}
	}

	/**
	 * Command tower for switching response-generating AI
	 */
	public function stream_chat_completion( $question, $context_chunks, $history = [], $provider ) {

		/**
		 * Fires before the default stream completion handlers.
		 * Allows Pro add-on or other plugins to implement their own handlers.
		 * The hooked function should call exit() to prevent default execution.
		 *
		 * @param string $question       User's question.
		 * @param array  $context_chunks Relevant context chunks.
		 * @param array  $history        Conversation history.
		 */
		do_action( "fe_ai_search_stream_for_{$provider}", $question, $context_chunks, $history, $provider );

		switch ( $provider ) {
			case 'google':
				$this->stream_completion_for_gemini( $question, $context_chunks, $history, $provider );
				break;
			case 'anthropic':
				$this->stream_completion_for_claude( $question, $context_chunks, $history, $provider );
				break;
			case 'openai':
			default:
				$this->stream_completion_for_openai( $question, $context_chunks, $history, $provider );
				break;
		}
	}

	/**
	 * Streams chat completions from the OpenAI (or compatible) API.
	 *
	 * Resolves the appropriate API endpoint and model based on the selected
	 * provider and license status, builds the prompt messages (including
	 * retrieved context and chat history), and then opens a streaming
	 * connection to the OpenAI-compatible chat completions endpoint.
	 *
	 * The response is streamed back to the caller via Server-Sent Events (SSE)
	 * format, and key events (start, errors, etc.) are recorded using
	 * FE_AI_Search_Logger when debug logging is enabled.
	 *
	 * @param string $question        The end user's original question.
	 * @param array  $context_chunks  Retrieved context chunks to be added to the prompt.
	 * @param array  $history         Prior chat messages used to maintain conversation state.
	 * @param string $provider        The provider slug ('openai' or 'openai_compatible').
	 *
	 * @return void Outputs streamed SSE responses directly and terminates.
	 */
	private function stream_completion_for_openai( $question, $context_chunks, $history, $provider ) {
		// Start the timer for System Log.
		$start_time = microtime( true );

		if ( 'openai_compatible' === $provider ) {
			$api_url = $this->options['provider']['openai_compatible_endpoint'] ?? '';
			$api_key = $this->options['provider']['openai_compatible_key'] ?? '';
		} else {
			$api_url = 'https://api.openai.com/v1/chat/completions';
			// Admin UI stores the key at provider.openai_key.
			$api_key = $this->options['provider']['openai_key'] ?? '';
		}

		if ( empty( $api_key ) || empty( $api_url ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Chat completion failed: API Key or Endpoint URL is not set.',
				[ 'provider' => $provider ]
			);

			echo 'data: ' . json_encode( [ 'text' => __( 'Error: OpenAI API key not set.', 'fe-ai-search' ) ] ) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		// DEBUG: Log context chunk statistics (count and first few permalinks).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$chunk_count = is_array( $context_chunks ) ? count( $context_chunks ) : 0;
			$permalinks  = [];
			if ( $chunk_count > 0 ) {
				foreach ( $context_chunks as $idx => $chunk ) {
					if ( $idx >= 3 ) {
						break;
					}
					if ( isset( $chunk['permalink'] ) ) {
						$permalinks[] = $chunk['permalink'];
					}
				}
			}
		}

		// DEBUG: (removed misplaced Gemini debug block from OpenAI handler)

		$model = 'gpt-4o-mini'; // Default
		if ( $this->is_license_active ) {
			if ( 'openai_compatible' === $provider ) {
				$model = $this->options['model']['openai_compatible'] ?? $model;
			} else {
				$model = $this->options['model']['openai'] ?? [ 'type' => $model ];
				$model = ( isset( $model['type'] ) && 'custom' === $model['type'] ) ? $model['custom'] : $model['type'];
			}
		}

		// Log the start of the API call.
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'Chat completion stream started.',
			[
				'provider' => $provider,
				'model'    => $model,
			]
		);

		$messages = $this->build_prompt_messages(
			$question,
			$context_chunks,
			$history,
			false
		);

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

		// The WRITEFUNCTION callback processes the stream chunks as they arrive.
		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $curl, $data ) {

				$lines = explode( "\n", $data );

				foreach ( $lines as $line ) {
					if ( strpos( $line, 'data: ' ) === 0 ) {

						$json_data = substr( $line, 6 );
						if ( trim( $json_data ) === '[DONE]' ) {
							continue;
						}
						$chunk = json_decode( $json_data, true );

						if ( isset( $chunk['choices'][0]['delta']['content'] ) ) {

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
							$content = apply_filters( 'fe_ai_search_filter_model_response', $chunk['choices'][0]['delta']['content'] );

							echo 'data: ' . json_encode( [ 'text' => $content ] ) . "\n\n";
							if ( ob_get_level() > 0 ) {
								ob_flush();
							}
							flush();
						}
					}
				}
				return strlen( $data );
			}
		);

		// Execute the request.
		curl_exec( $ch );

		// Calculate the total duration.
		$duration = round( ( microtime( true ) - $start_time ) * 1000 );

		// Retrieve HTTP status code for additional diagnostics (e.g., rate limit).
		$http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		// If the API responded with a rate limit status, notify the site administrator.
		if ( 429 === (int) $http_status ) {
			$this->notify_rate_limit(
				'chat_openai',
				[
					'provider'    => $provider,
					'model'       => $model,
					'http_status' => $http_status,
				]
			);
		}

		// Check for cURL errors after execution.
		if ( curl_errno( $ch ) ) {
			// ERROR: Log the cURL (connection) failure.
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Chat completion stream failed (cURL Error).',
				[
					'provider'      => $provider,
					'model'         => $model,
					'error_message' => curl_error( $ch ),
					'duration_ms'   => $duration,
					'http_status'   => $http_status,
				]
			);
		} else {
			// INFO: Log the successful completion of the stream.
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'INFO',
				'Chat completion stream finished successfully.',
				[
					'provider'    => $provider,
					'model'       => $model,
					'duration_ms' => $duration,
					'http_status' => $http_status,
				]
			);
		}

		curl_close( $ch );
	}

	/**
	 * Sends a notification email to the site administrator when a rate limit
	 * is detected from a chat completion API.
	 *
	 * @param string $context Short identifier of where the rate limit occurred.
	 * @param array  $details Additional diagnostic information.
	 *
	 * @return void
	 */
	private function notify_rate_limit( $context, $details = [] ) {
		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return;
		}

		$subject = sprintf(
			'[FE AI Search] Rate limit reached: %s',
			$context
		);

		$body  = "A rate limit error occurred in FE AI Search.\n\n";
		$body .= sprintf( "Context: %s\n", $context );
		foreach ( $details as $key => $value ) {
			$body .= sprintf( "%s: %s\n", $key, $value );
		}
		$body .= "\nSite URL: " . home_url( '/' ) . "\n";

		wp_mail( $admin_email, $subject, $body );
	}

	/**
	 * Streams chat completions from the Gemini API.
	 *
	 * Resolves the Gemini model and endpoint based on the current settings
	 * and license status, builds the prompt messages (including retrieved
	 * context and chat history), and then opens a streaming connection to
	 * the Gemini chat endpoint.
	 *
	 * The response is streamed back to the caller via Server-Sent Events (SSE)
	 * compatible output, and key events (start, errors, etc.) are recorded
	 * using FE_AI_Search_Logger when debug logging is enabled.
	 *
	 * @param string $question        The end user's original question.
	 * @param array  $context_chunks  Retrieved context chunks to be added to the prompt.
	 * @param array  $history         Prior chat messages used to maintain conversation state.
	 * @param string $provider        The provider slug for Gemini (e.g. 'google').
	 *
	 * @return void Outputs streamed SSE responses directly and terminates.
	 */
	private function stream_completion_for_gemini( $question, $context_chunks, $history, $provider ) {
		$start_time = microtime( true );

		// DEBUG: Basic Gemini state for troubleshooting.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		}

		// Admin UI stores the key at provider.google_key.
		$api_key = $this->options['provider']['google_key'] ?? '';
		if ( empty( $api_key ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Chat completion failed: API Key or Endpoint URL is not set.',
				[ 'provider' => $provider ]
			);

			echo 'data: ' . json_encode( [ 'text' => __( 'Error: Google API key is not set.', 'fe-ai-search' ) ] ) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$model = 'gemini-2.5-flash-lite'; // Default
		if ( $this->is_license_active ) {
			// Read Gemini model settings from Pro options (fe_ai_search_pro_settings).
			$pro_settings   = get_option( 'fe_ai_search_pro_settings', [] );
			$google_options = $pro_settings['model']['google_model'] ?? [];
			if ( is_array( $google_options ) ) {
				$type   = $google_options['type'] ?? '';
				$custom = $google_options['custom'] ?? '';
				if ( 'custom' === $type && '' !== trim( (string) $custom ) ) {
					$model = $custom;
				} elseif ( '' !== $type ) {
					$model = $type;
				}
			}
		}

		// DEBUG: Log the resolved Gemini model once per request for troubleshooting.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		}

		// INFO: Log the start of the API call.
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'Chat completion stream started.',
			[
				'provider' => $provider,
				'model'    => $model,
			]
		);

		$messages = self::build_prompt_messages(
			$question,
			$context_chunks,
			$history,
			false
		);

		// DEBUG: Log a view of the prompt payload before sending to Gemini.
		// - System prompt: full text
		// - Context chunks: each summarized as permalink + first ~80 chars of content
		// - Question: original user question (without embedded Site Information)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$messages_copy     = $messages;
			$system_full       = '';
			$context_summaries = [];
			if ( ! empty( $messages_copy ) && isset( $messages_copy[0]['role'] ) && 'system' === $messages_copy[0]['role'] ) {
				$system_full = (string) ( $messages_copy[0]['content'] ?? '' );
			}
			if ( is_array( $context_chunks ) && ! empty( $context_chunks ) ) {
				foreach ( $context_chunks as $idx => $chunk ) {
					// Limit the number of logged chunks to avoid excessive log size.
					if ( $idx >= 10 ) {
						break;
					}
					$context_summaries[] = [
						'permalink' => $chunk['permalink'] ?? '',
						'head'      => mb_substr( (string) ( $chunk['content_chunk'] ?? '' ), 0, 80, 'UTF-8' ),
					];
				}
			}
			$debug_payload = [
				'system'            => $system_full,
				'question'          => $question,
				'context_summaries' => $context_summaries,
				'message_cnt'       => count( $messages_copy ),
			];
		}

		$system_prompt_data = array_shift( $messages );
		$system_prompt_text = $system_prompt_data['content'];

		$gemini_contents = [];
		foreach ( $messages as $message ) {
			$role = ( $message['role'] === 'assistant' ) ? 'model' : 'user';
			if ( ! empty( $gemini_contents ) && end( $gemini_contents )['role'] === $role ) {
				continue;
			}
			$gemini_contents[] = [
				'role'  => $role,
				'parts' => [
					[ 'text' => $message['content'] ],
				],
			];
		}

		$api_url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
			$model,
			$api_key
		);

		$body = [
			'contents'          => $gemini_contents,
			'systemInstruction' => [
				'parts' => [
					[ 'text' => $system_prompt_text ],
				],
			],
		];

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $api_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $curl, $data ) {

				$lines = explode( "\n", $data );

				foreach ( $lines as $line_index => $line ) {
					if ( strpos( $line, 'data: ' ) === 0 ) {

						$json_data = substr( $line, 6 );
						$chunk     = json_decode( $json_data, true );

						if ( is_array( $chunk ) && isset( $chunk['candidates'][0]['content']['parts'][0]['text'] ) ) {

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
							$content = apply_filters( 'fe_ai_search_filter_model_response', $content );

							echo 'data: ' . json_encode( [ 'text' => $content ] ) . "\n\n";
							if ( ob_get_level() > 0 ) {
								ob_flush();
							}
							flush();
						}
					}
				}
				return strlen( $data );
			}
		);

		// Execute the request.
		curl_exec( $ch );
		echo "data: [DONE]\n\n";
		flush();

		// Calculate the total duration.
		$duration = round( ( microtime( true ) - $start_time ) * 1000 );

		// Check for cURL errors after execution.
		if ( curl_errno( $ch ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Chat completion stream failed (cURL Error).',
				[
					'provider'      => $provider,
					'model'         => $model,
					'error_message' => curl_error( $ch ),
					'duration_ms'   => $duration,
				]
			);
		} else {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'INFO',
				'Chat completion stream finished successfully.',
				[
					'provider'    => $provider,
					'model'       => $model,
					'duration_ms' => $duration,
				]
			);
		}

		curl_close( $ch );
	}

	/**
	 * Streams chat completions from the Anthropic Claude API.
	 *
	 * Resolves the Claude model and endpoint based on the current settings
	 * and license status, builds the prompt messages (including retrieved
	 * context and chat history), and then opens a streaming connection to
	 * the Claude messages endpoint.
	 *
	 * The response is streamed back to the caller via Server-Sent Events (SSE)
	 * compatible output, and key events (start, errors, etc.) are recorded
	 * using FE_AI_Search_Logger when debug logging is enabled.
	 *
	 * @param string $question        The end user's original question.
	 * @param array  $context_chunks  Retrieved context chunks to be added to the prompt.
	 * @param array  $history         Prior chat messages used to maintain conversation state.
	 * @param string $provider        The provider slug for Claude (e.g. 'anthropic').
	 *
	 * @return void Outputs streamed SSE responses directly and terminates.
	 */
	private function stream_completion_for_claude( $question, $context_chunks, $history, $provider ) {
		// Start the timer.
		$start_time = microtime( true );

		// Admin UI stores the key at provider.anthropic_key.
		$api_key = $this->options['provider']['anthropic_key'] ?? '';
		if ( empty( $api_key ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Chat completion failed: API Key or Endpoint URL is not set.',
				[ 'provider' => $provider ]
			);

			echo 'data: ' . json_encode(
				[ 'text' => __( 'Error: Anthropic API key not set.', 'fe-ai-search' ) ]
			) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$model = 'claude-haiku-4-5-20251001'; // Default
		if ( $this->is_license_active ) {
			$model = $this->options['model']['anthropic'] ?? [ 'type' => $model ];
			$model = ( 'custom' === $model['type'] ) ? $model['custom'] : $model['type'];
		}

		// INFO: Log the start of the API call.
		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'Chat completion stream started.',
			[
				'provider' => $provider,
				'model'    => $model,
			]
		);

		$messages = self::build_prompt_messages(
			$question,
			$context_chunks,
			$history,
			true  // This separates the system prompt for Claude.
		);

		$body = [
			'model'      => $model,
			'max_tokens' => 4096,
			'messages'   => $messages['messages'],
			'system'     => $messages['system'],
			'stream'     => true,
		];

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			[
				'Content-Type: application/json',
				'x-api-key: ' . $api_key,
				'anthropic-version: 2023-06-01',
			]
		);
		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $curl, $data ) {

				$lines = explode( "\n", $data );

				foreach ( $lines as $line ) {
					if ( strpos( $line, 'data: ' ) === 0 ) {

						$json_data = substr( $line, 6 );
						$chunk     = json_decode( $json_data, true );

						if ( isset( $chunk['type'] )
						&& $chunk['type'] === 'content_block_delta'
						&& isset( $chunk['delta']['type'] ) && $chunk['delta']['type'] === 'text_delta' ) {

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
							$content = apply_filters( 'fe_ai_search_filter_model_response', $content );

							echo 'data: ' . json_encode( [ 'text' => $content ] ) . "\n\n";

							if ( ob_get_level() > 0 ) {
								ob_flush();
							}
							flush();
						}
					}
				}
				return strlen( $data );
			}
		);

		curl_exec( $ch );
		echo "data: [DONE]\n\n";
		flush();

		// Calculate the total duration.
		$duration = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( curl_errno( $ch ) ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'ERROR',
				'Chat completion stream failed (cURL Error).',
				[
					'provider'      => $provider,
					'model'         => $model,
					'error_message' => curl_error( $ch ),
					'duration_ms'   => $duration,
				]
			);
		} else {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'INFO',
				'Chat completion stream finished successfully.',
				[
					'provider'    => $provider,
					'model'       => $model,
					'duration_ms' => $duration,
				]
			);
		}

		curl_close( $ch );
	}

	/**
	 * Builds a provider-agnostic messages array for chat completion APIs.
	 *
	 * Assembles the system prompt (including site-specific rules and language
	 * instructions), attaches retrieved context chunks, and merges prior chat
	 * history with the current user question into a unified messages structure.
	 * This structure is then consumed by provider-specific streaming methods
	 * (OpenAI, Gemini, Claude, etc.).
	 *
	 * @param string $question       The end user's original question.
	 * @param array  $context_chunks Retrieved context chunks to be injected into the prompt.
	 * @param array  $history        Prior conversation history messages.
	 * @param bool   $for_claude     Whether to format messages for Claude's API shape.
	 *
	 * @return array The messages payload ready to be sent to the chat API.
	 */
	public function build_prompt_messages( $question, $context_chunks, $history, $for_claude = false ) {
		$context_str = '';

		// Provider slug from provider settings.
		$provider = $this->options['provider']['chat'] ?? 'openai';

		// Get the default prompt
		$system_prompt = self::get_default_system_prompt();

		// If there is a basic prompt, overwrite with it.
		$free_prompt = $this->options['prompt']['system_prompt'] ?? '';
		if ( ! empty( $free_prompt ) ) {
			$system_prompt = $free_prompt;
		}

		// If the Pro version is enabled and there are model-specific settings, they will overwrite accordingly.
		if ( $this->is_license_active ) {
			$custom_prompts = $this->options['prompt'];

			if ( ! empty( $custom_prompts[ $provider ] ) ) {
				$system_prompt = $custom_prompts[ $provider ];
			}
		}

		// Get site info and replace placeholders in the prompt.
		$site_name    = $this->options['prompt']['site_name'] ?? get_bloginfo( 'name' );
		$site_purpose = $this->options['prompt']['site_purpose'] ?? get_bloginfo( 'description' );
		$site_url     = home_url( '/' );

		$system_prompt = str_replace( '{site_name}', $site_name, $system_prompt );
		$system_prompt = str_replace( '{site_purpose}', $site_purpose, $system_prompt );
		$system_prompt = str_replace( '{site_url}', $site_url, $system_prompt );

		// Detect the language that should be used for answers, based on the current
		// WordPress locale and, if available, the active multilingual plugin.
		$answer_locale = get_locale();
		if ( function_exists( 'pll_current_language' ) ) {
			// Polylang: try to get the current locale (e.g. ja, en_US, etc.).
			$pll_locale = pll_current_language( 'locale' );
			if ( ! empty( $pll_locale ) ) {
				$answer_locale = $pll_locale;
			}
		} elseif ( function_exists( 'wpml_get_language_information' ) && defined( 'ICL_LANGUAGE_CODE' ) ) {
			// WPML: language code like 'en', 'ja'. Map it to a pseudo-locale.
			$lang_code = ICL_LANGUAGE_CODE;
			if ( ! empty( $lang_code ) ) {
				$answer_locale = $lang_code;
			}
		} elseif ( function_exists( 'bogo_get_locale' ) ) {
			// Bogo: site locale per request.
			$bogo_locale = bogo_get_locale();
			if ( ! empty( $bogo_locale ) ) {
				$answer_locale = $bogo_locale;
			}
		}

		$answer_lang_code = strstr( $answer_locale, '_', true ) ?: $answer_locale; // e.g. ja_JP -> ja

		// Append a language rule so the model knows which language to use
		// for this particular request.
		$system_prompt .= "\n\n## Language Rule\n" .
			"All of your responses must be written in the language corresponding to the current WordPress locale code '{$answer_lang_code}'. ";

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
		$system_prompt = apply_filters( 'fe_ai_search_system_prompt', $system_prompt, $provider );

		// Legal links are managed under the Display > Text/Links settings.
		$links           = $this->options['display']['links'] ?? [];
		$terms_page_id   = $links['terms_page_id'] ?? 0;
		$privacy_page_id = $links['privacy_page_id'] ?? 0;

		// Add the legal document at the beginning of the context.
		$legal_context = '';
		if ( $terms_page_id ) {
			$page = get_post( $terms_page_id );
			if ( $page ) {
				$legal_context .= "--- START: Terms of Service ---\n" . wp_strip_all_tags( $page->post_content ) . "\n--- END: Terms of Service ---\n\n";
			}
		}
		if ( $privacy_page_id ) {
			$page = get_post( $privacy_page_id );
			if ( $page ) {
				$legal_context .= "--- START: Privacy Policy ---\n" . wp_strip_all_tags( $page->post_content ) . "\n--- END: Privacy Policy ---\n\n";
			}
		}
		if ( ! empty( $legal_context ) ) {
			$context_str .= "Mandatory Compliance Documents:\n" . $legal_context;
		}

		// Construct a context string
		if ( ! empty( $context_chunks ) ) {
			$context_str = "Site Information:\n";
			foreach ( $context_chunks as $chunk ) {
				$context_str .= "--- START OF ARTICLE ---\n" .
								'Source URL: ' . $chunk['permalink'] . "\n" .
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
		$messages = array_filter(
			$history,
			function ( $msg ) {
				return ! ( isset( $msg['role'] ) && $msg['role'] === 'assistant' && empty( trim( $msg['content'] ) ) );
			}
		);

		$user_question_with_context = $context_str . "\n\nBased on the Site Information provided above, answer the following question:\n" . $question;

		$messages[] = [
			'role'    => 'user',
			'content' => $user_question_with_context,
		];

		// Return the final data in the format specified by the provider.
		if ( $for_claude ) {
			return [ 'messages' => array_values( $messages ), 'system' => $system_prompt ];
		} else { // for OpenAI & Gemini
			array_unshift( $messages, [ 'role' => 'system', 'content' => $system_prompt ] );
			return $messages;
		}
	}

	/**
	 * Returns the default system prompt.
	 *
	 * @return string
	 */
	public static function get_default_system_prompt() {
		return "You are a helpful and honest assistant for the website '{site_name}'. This site's purpose is: '{site_purpose}'. The official website URL is: '{site_url}'. You must strictly answer only about the content and topics related to this website.

Based on the provided \"Site Information\" and \"Conversation History,\" please answer by strictly following the rules below.

## Thought Process
1. Accurately understand the intent of the user's latest question.
2. Read the \"Site Information\" and identify the parts most relevant to the question.
3. If no relevant information is found in the \"Site Information,\" honestly state, \"I could not find an answer to your question from the information on this site.\".
4. If relevant information is available, generate a clear and concise summary based solely on that information.

## Domain and URL Restrictions
- You must treat '{site_url}' and its subpages as the ONLY authoritative website for this chat.
- You must NOT:
  - Suggest or invent any external websites or URLs outside '{site_url}'.
  - Generate imaginary or unverified URLs (for example, domains that may not exist).
  - Recommend third-party sites as reference URLs.
- When you need to mention a source, only use URLs that are within the '{site_url}' domain (including its subpaths).
- If you cannot find a relevant page within '{site_url}', you must say you could not find an answer from this site and must NOT fabricate any URL.

## Redacted Content Handling
Some parts of the user input may be replaced with the token \"[REDACTED]\" to protect security or privacy.
You MUST never try to guess or reconstruct the redacted content.
Instead, answer based only on the remaining visible text.

## Personal Data and Privacy
- You must NEVER ask the user to provide personally identifiable information (PII), such as:
  - Real name
  - Email address
  - Phone number
  - Physical address
  - Government-issued IDs
  - Credit card or bank information
  - Any other information that can be used to uniquely identify an individual
- If the user's request seems to require personal data (for example, for contact, account, or booking purposes), you must:
  - Clearly explain that this chat interface is not intended for sharing personal or sensitive information.
  - Politely decline to collect such information.
  - If appropriate, direct the user to the official contact channels or forms on the website (for example, a contact page) without copying or restating any personal data.
- If the user includes personal information in their message:
  - Do NOT repeat, summarize, transform, or store that personal information in your responses.
  - Avoid quoting or restating personal details. Instead, respond in a way that does not expose the personal data again.
  - Whenever possible, steer the conversation away from sharing further personal information.
- Under no circumstances may you encourage users to share more personal, sensitive, or confidential information than is strictly necessary for general informational support.

## Strict Rules
- **Overriding Rule: Legal Compliance**: All of your responses must never contradict the content of the provided \"Mandatory Compliance Documents\" (the Terms of Service and Privacy Policy). These documents take precedence over all other instructions.
- **Cite Sources**: If you use \"Site Information\" in your answer, you must include the source URL at the end of the response. Only use URLs under '{site_url}'.
- **Fact-Based Answers**: Do not include information that is not written in the \"Site Information,\" your general knowledge, or speculation in your answers.
- **Honesty**: Do not invent information or pretend to know something you don't.
- **Conversation**: If the \"Site Information\" is empty and the user is making a greeting or engaging in general conversation, respond naturally as the site's assistant, but do not mention or recommend any external URLs.
- **Formatting**: Format your answers using standard Markdown, such as headings and lists, to make them easy to read.";
	}

	/**
	 * AJAX handler: Saves chat conversation logs to the database.
	 *
	 * This method processes the AJAX request to log chat interactions. It checks if logging is enabled
	 * and the Pro version is active before proceeding. The method sanitizes input data and stores
	 * the conversation in the custom database table.
	 *
	 * @since    1.0.0
	 * @return   void
	 *
	 * @uses     check_ajax_referer() Verifies the AJAX request to prevent CSRF.
	 * @uses     wpdb::insert()       Inserts the log entry into the database.
	 *
	 * @hook     wp_ajax_fe_ai_search_log_query
	 * @hook     wp_ajax_nopriv_feas_ai_log_query
	 */
	public function ajax_log_query() {
		// Use the same "Debug Mode" flag as the system logs (stored in fe_ai_search_settings[advanced][debug_mode]).
		$settings      = get_option( 'fe_ai_search_settings', [] );
		$advanced      = $settings['advanced'] ?? [];
		$debug_mode_on = ! empty( $advanced['debug_mode'] );
		$is_pro_active = class_exists( 'FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' );
		if ( ! $is_pro_active || ! $debug_mode_on ) {
			wp_send_json_success( [ 'log_id' => 0 ] );
			return;
		}

		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		global $wpdb;
		$logs_table = $wpdb->prefix . 'fe_ai_search_logs';

		$question = isset( $_POST['question'] ) ? sanitize_text_field( $_POST['question'] ) : '';
		$answer   = isset( $_POST['answer'] ) ? wp_kses_post( $_POST['answer'] ) : '';

		// Apply personal data filter before logging, as an additional safety net.
		$question      = $this->filter_personal_data( $question );
		$answer        = $this->filter_personal_data( $answer );
		$session_id    = isset( $_POST['session_id'] ) ? sanitize_key( $_POST['session_id'] ) : '';
		$context_found = isset( $_POST['context_found'] ) ? (bool) $_POST['context_found'] : false;
		$question_len  = isset( $_POST['question_length'] ) ? intval( $_POST['question_length'] ) : 0;
		$log_id        = 0;

		// For privacy protection, do not store the full question text. Only create a log entry
		// when both a session ID and an answer are available.
		if ( ! empty( $session_id ) && ! empty( $answer ) ) {
			$wpdb->insert(
				$logs_table,
				[
					'session_id'    => $session_id,
					'question'      => sprintf( 'User question is not logged. (length: %d chars)', max( 0, $question_len ) ),
					'answer'        => $answer,
					'context_found' => $context_found,
					'created_at'    => current_time( 'mysql' ),
				]
			);
			$log_id = $wpdb->insert_id;
		}

		wp_send_json_success( [ 'log_id' => $log_id ] );
	}

	/**
	 * AJAX handler: Logs the user's privacy consent decision to the system logs.

	 * Records a simple INFO-level event in the {prefix}fe_ai_search_system_logs table
	 * when the user accepts the privacy consent overlay in the frontend chat UI.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_log_consent() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
		$source     = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'chat_overlay';

		\FEAISearch\Core\FE_AI_Search_Logger::log(
			'INFO',
			'User accepted privacy consent for AI chat.',
			[
				'session_id' => $session_id,
				'source'     => $source,
			]
		);

		wp_send_json_success();
	}

	/**
	 * Handles the AJAX request to test an AI provider's API key.
	 *
	 * This method receives a provider slug and an API key. It attempts to
	 * authenticate against the provider's endpoint.
	 *
	 * It first checks a filter ('fe_ai_search_handle_custom_api_test') to allow
	 * add-ons (like the Pro version) to handle their own provider tests.
	 * If not handled by an add-on, it proceeds to test the built-in
	 * providers (OpenAI, Google, Anthropic).
	 *
	 * It sends a JSON response indicating success or failure.
	 *
	 * @since 1.0.0
	 * @hook wp_ajax_fe_ai_search_test_api_key
	 */
	public function ajax_test_api_key() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );
		$provider = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';

		if ( empty( $provider ) ) {
			wp_send_json_error( __( 'Provider missing.', 'fe-ai-search' ) );
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
		$result = apply_filters( 'fe_ai_search_handle_custom_api_test', $result, $provider, $api_key );

		if ( is_array( $result ) ) {

			$is_valid      = $result['is_valid'];
			$error_message = $result['message'];

		} else {

			$is_valid      = false;
			$error_message = '';

			switch ( $provider ) {
				case 'openai':
					if ( empty( $api_key ) ) {
						$error_message = __( 'API key is missing.', 'fe-ai-search' );
						break;
					}
					$response = wp_remote_get(
						'https://api.openai.com/v1/models',
						[
							'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
						]
					);
					if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
						$is_valid = true;
					} else {
						$error_message = is_wp_error( $response ) ? $response->get_error_message() : __( 'Authentication failed.', 'fe-ai-search' );
					}
					break;

				case 'google':
					if ( empty( $api_key ) ) {
						$error_message = __( 'API key is missing.', 'fe-ai-search' );
						break;
					}
					$api_url  = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
					$response = wp_remote_get( $api_url );

					if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
						$is_valid = true;
					} else {
						$error_message = __( 'Authentication failed.', 'fe-ai-search' );
						if ( ! is_wp_error( $response ) ) {
							$body          = json_decode( wp_remote_retrieve_body( $response ), true );
							$error_message = $body['error']['message'] ?? $error_message;
						}
					}
					break;

				case 'anthropic':
					if ( empty( $api_key ) ) {
						$error_message = __( 'API key is missing.', 'fe-ai-search' );
						break;
					}
					$response = wp_remote_post(
						'https://api.anthropic.com/v1/messages',
						[
							'headers' => [
								'x-api-key'         => $api_key,
								'anthropic-version' => '2023-06-01',
								'Content-Type'      => 'application/json',
							],
							'body'    => json_encode(
								[
									'model'      => 'claude-3-haiku-20240307',
									'max_tokens' => 1,
									'messages'   => [ [ 'role' => 'user', 'content' => 'Hello' ] ],
								]
							),
						]
					);

					if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
						$is_valid = true;
					} else {
						$error_message = __( 'Authentication failed.', 'fe-ai-search' );
						if ( ! is_wp_error( $response ) ) {
							$body          = json_decode( wp_remote_retrieve_body( $response ), true );
							$error_message = $body['error']['message'] ?? $error_message;
						}
					}
					break;

				default:
					$error_message = __( 'Unknown provider.', 'fe-ai-search' );
			}
			$result = [ 'is_valid' => $is_valid, 'message' => $error_message ];
		}

		if ( $result['is_valid'] ) {
			wp_send_json_success( '<span style="color: green;">✔ ' . __( 'Connection successful', 'fe-ai-search' ) . '</span>' );
		} else {
			wp_send_json_error( '<span style="color: red;">✖ ' . __( 'Connection failed', 'fe-ai-search' ) . ': ' . $result['message'] . '</span>' );
		}
	}

	/**
	 * Filters text for basic prompt injection phrases from all available languages.
	 *
	 * This function loads injection phrases from all files in the i18n directory
	 * to provide a robust, multilingual security filter regardless of the site's locale.
	 *
	 * @param string $text The text to filter.
	 * @return string The filtered text.
	 */
	public function filter_basic_injection_phrases( $text ) {
		$all_injection_phrases = [];
		$i18n_dir              = FE_AI_SEARCH_PLUGIN_DIR . 'includes/i18n/';

		// Find all language files in the i18n directory.
		$lang_files = glob( $i18n_dir . '*.php' );

		if ( ! empty( $lang_files ) ) {
			foreach ( $lang_files as $file ) {
				$lang_data = include $file;
				if ( ! empty( $lang_data['injection_phrases'] ) && is_array( $lang_data['injection_phrases'] ) ) {
					// Merge phrases from all language files into one master list.
					$all_injection_phrases = array_merge( $all_injection_phrases, $lang_data['injection_phrases'] );
				}
			}
		}

		// Remove any potential duplicates.
		$all_injection_phrases = array_unique( $all_injection_phrases );

		if ( empty( $all_injection_phrases ) ) {
			return $text;
		}

		// Filter the text against the comprehensive list of phrases.
		$filtered_text = str_ireplace( $all_injection_phrases, __( '[REDACTED]', 'fe-ai-search' ), $text );

		// SECURITY: Log if a basic security filter was triggered.
		if ( $filtered_text !== $text ) {
			\FEAISearch\Core\FE_AI_Search_Logger::log(
				'SECURITY',
				'Basic prompt injection filter triggered.',
				[ 'original_text' => mb_substr( $text, 0, 100 ) . '...' ] // Log a snippet for context
			);
		}

		return $filtered_text;
	}

	/**
	 * Filters basic personal data patterns (email, phone numbers, postal codes) from text.
	 *
	 * This is a best-effort, pattern-based filter intended to reduce the chance of
	 * accidentally storing or forwarding personally identifiable information (PII)
	 * such as email addresses or phone numbers. It does not guarantee perfect
	 * coverage for all formats.
	 *
	 * @param string $text The text to filter.
	 * @return string The filtered text with detected PII masked.
	 */
	public function filter_personal_data( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		$patterns = [
			// Email addresses (simple pattern).
			'/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
			// Phone numbers (international or domestic, allowing spaces, dashes, parentheses).
			'/\+?\d[0-9\-()\s]{7,}\d/',
		];

		/**
		 * Filters the regex patterns used to detect personal data.
		 *
		 * Developers can override or extend the default patterns by returning
		 * a custom array of regular expressions.
		 *
		 * @since 1.0.0
		 *
		 * @param array $patterns Default regex patterns for personal data.
		 */
		$patterns = apply_filters( 'fe_ai_search_personal_data_patterns', $patterns );

		$filtered = preg_replace( $patterns, __( '[REDACTED]', 'fe-ai-search' ), $text );

		if ( null === $filtered ) {
			// If a regex error occurs, fail safely by returning the original text.
			return $text;
		}

		return $filtered;
	}

	/**
	 * Handles the AJAX request to activate or deactivate the Pro license.
	 *
	 * This method communicates with the License_Handler and updates the
	 * single master settings array ('fe_ai_search_settings') with the new
	 * license key, status, and data.
	 *
	 * @since 1.0.0
	 * @hook wp_ajax_fe_ai_search_manage_license
	 */
	public function ajax_manage_license() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( $_POST['license_key'] ) : '';
		$action      = isset( $_POST['license_action'] ) ? sanitize_key( $_POST['license_action'] ) : '';

		if ( empty( $license_key ) || empty( $action ) ) {
			wp_send_json_error( [ 'message' => 'Missing key or action.' ] );
		}

		// Perform the license action.
		$handler = new \FEAISearch\Core\FE_AI_Search_License_Handler();
		$result  = ( 'activate' === $action ) ? $handler->activate( $license_key ) : $handler->deactivate( $license_key );
		if ( $result && $result['success'] ) {
			// Determine the local license status based on the requested action.
			// For this site, a successful deactivation should always be treated as inactive,
			// even if the remote license itself remains valid for other activations.
			$local_status = ( 'activate' === $action ) ? 'active' : 'inactive';

			$license = [
				'key'    => $license_key,
				'status' => $local_status,
				'data'   => $result['data'] ?? [],
			];
			update_option( 'fe_ai_search_license', $license );
			delete_transient( 'fe_ai_search_license_error' );
			$send_response = 'wp_send_json_success';

		} else {
			$license = [
				'key'    => $license_key,
				'status' => 'inactive',
				'data'   => $result['data'] ?? [],
			];
			update_option( 'fe_ai_search_license', $license );
			set_transient( 'fe_ai_search_license_error', $result['message'], 60 );
			$send_response = 'wp_send_json_error';
		}

		// Send the final JSON response.
		$send_response( [ 'message' => $result['message'] ] );
	}
}
