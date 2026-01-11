<?php
/**
 * Handles all AJAX and REST API communication for the chat functionality.
 *
 * This file defines the FE_Search_AI_Chat_Handler class, which is responsible for
 * registering API endpoints, processing incoming chat requests (streaming and non-streaming),
 * dispatching them to the correct AI model, and handling conversation logging.
 *
 * @package    fe-search-ai
 * @subpackage Ajax
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FESearchAI\Core\FE_Search_AI_Encryption_Helper;
use FESearchAI\Core\FE_Search_AI_License;

/**
 * Main controller for all frontend chat interactions.
 *
 * This class sets up the necessary endpoints and callbacks to handle
 * real-time streaming chat, non-streaming API queries, conversation logging,
 * and API key validation tests.
 *
 * @since      1.0.0
 * @package    fe-search-ai
 * @subpackage Ajax
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Chat_Handler {

	private $options           = [];
	private $is_license_active = '';
	private $sync_handler;

	/**
	 * Constructor: Initializes the chat handler with dependencies and hooks.
	 *
	 * Sets up the chat functionality by loading plugin options, checking license
	 * status for Pro features, storing the sync handler reference, and registering
	 * REST API endpoints and AJAX action handlers for chat operations.
	 *
	 * @since 1.0.0
	 * @param FE_Search_AI_Sync_Handler $sync_handler The sync handler instance for data retrieval.
	 * @return void
	 */
	public function __construct( $sync_handler ) {
		$this->options           = get_option( 'fe_search_ai_settings', [] );
		$this->is_license_active = FE_Search_AI_License::is_pro_active();
		$this->sync_handler      = $sync_handler;

		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );
		add_action( 'wp_ajax_nopriv_fe_search_ai_log_query', [ $this, 'ajax_log_query' ] );
		add_action( 'wp_ajax_fe_search_ai_log_query', [ $this, 'ajax_log_query' ] );
		add_action( 'wp_ajax_nopriv_fe_search_ai_log_consent', [ $this, 'ajax_log_consent' ] );
		add_action( 'wp_ajax_fe_search_ai_log_consent', [ $this, 'ajax_log_consent' ] );
		add_action( 'wp_ajax_nopriv_fe_search_ai_get_session_logs', [ $this, 'ajax_get_session_logs' ] );
		add_action( 'wp_ajax_fe_search_ai_get_session_logs', [ $this, 'ajax_get_session_logs' ] );
		add_action( 'wp_ajax_nopriv_fe_search_ai_rate_answer', [ $this, 'ajax_rate_answer' ] );
		add_action( 'wp_ajax_fe_search_ai_rate_answer', [ $this, 'ajax_rate_answer' ] );
		add_action( 'wp_ajax_fe_search_ai_test_api_key', [ $this, 'ajax_test_api_key' ] );
		add_action( 'wp_ajax_fe_search_ai_manage_license', [ $this, 'ajax_manage_license' ] );
		add_filter( 'fe_search_ai_filter_user_question', [ $this, 'filter_personal_data' ], 10 );
		add_filter( 'fe_search_ai_filter_user_question', [ $this, 'filter_basic_injection_phrases' ], 20 );
		add_filter( 'fe_search_ai_filter_model_response', [ $this, 'filter_basic_injection_phrases' ], 20 );
	}

	/**
	 * Registers REST API endpoints for streaming and non-streaming chat.
	 *
	 * This method registers the /stream endpoint for real-time chat responses
	 * and the /chat endpoint for standard API requests. Both endpoints handle
	 * AI model communication with proper permission callbacks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_endpoints() {
		register_rest_route(
			'fe-search-ai/v1',
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
		$rate_limit_options = apply_filters( 'fe_search_ai_rate_limit_settings', $default_limits );
		$rate_limit_options = wp_parse_args( $rate_limit_options, $default_limits );

		$ip_limit_count     = (int) $rate_limit_options['ip_limit_count'];
		$global_limit_count = (int) $rate_limit_options['global_limit_count'];
		$threshold_percent  = (int) $rate_limit_options['notify_threshold'];
		$notify_email       = $rate_limit_options['notify_email'];

		if ( $ip_limit_count >= 0 ) {
			$ip_transient_key = 'fe_search_ai_rl_ip_' . md5( $_SERVER['REMOTE_ADDR'] );
			$ip_request_count = get_transient( $ip_transient_key );

			if ( $ip_request_count > $ip_limit_count ) {
				status_header( 429 );
				echo 'data: ' . json_encode( [ 'text' => __( 'You have exceeded the request limit. Please try again later.', 'fe-search-ai' ) ] ) . "\n\n";
				exit;
			}
			set_transient( $ip_transient_key, ( (int) $ip_request_count + 1 ), HOUR_IN_SECONDS );
		}

		if ( $global_limit_count >= 0 ) {
			$global_transient_key = 'fe_search_ai_rl_global_day';
			$global_request_count = get_transient( $global_transient_key );

			if ( $global_request_count > $global_limit_count ) {
				status_header( 429 );
				echo 'data: ' . json_encode( [ 'text' => __( 'The site-wide request limit for today has been reached.', 'fe-search-ai' ) ] ) . "\n\n";
				exit;
			}

			$new_global_count = (int) $global_request_count + 1;
			set_transient( $global_transient_key, $new_global_count, DAY_IN_SECONDS );

			$threshold_count = ( $global_limit_count * $threshold_percent ) / 100;

			if ( $new_global_count > $threshold_count ) {
				if ( ! get_transient( 'fe_search_ai_rl_notify_sent' ) ) {
					$subject = get_bloginfo( 'name' ) . ' - AI Search Limit Warning';
					$message = "Your site's daily AI request limit is about to be reached. "
					. $new_global_count . ' / ' . $global_limit_count . ' requests have been used.';
					wp_mail( $notify_email, $subject, $message ); // Use the final email address
					set_transient( 'fe_search_ai_rl_notify_sent', true, DAY_IN_SECONDS );
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
			$question = apply_filters( 'fe_search_ai_filter_user_question', $question );
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
				$similar_chunks = apply_filters( 'fe_search_ai_retrieved_chunks', $similar_chunks, $question );

			} catch ( \Throwable $t ) {
				// Fail safely: log the error and continue without context.
				\FESearchAI\Core\FE_Search_AI_Logger::log(
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

			$this->stream_chat_completion( $question, $similar_chunks, $provider, $history );

		} catch ( \Exception $e ) {
			echo 'data: ' . json_encode( [ 'error' => 'An error occurred during processing.' ] ) . "\n\n";
			flush();
		} finally {
			exit;
		}
	}

	/**
	 * Command tower for switching response-generating AI providers.
	 *
	 * This method routes streaming chat requests to the appropriate AI provider
	 * (OpenAI, Google, Anthropic, etc.) based on the provider parameter. It allows
	 * Pro add-ons to override default handlers via action hooks.
	 *
	 * @since 1.0.0
	 * @param string $question       User's question text.
	 * @param array  $context_chunks Relevant context chunks for the AI.
	 * @param array  $history        Chat history for conversation context.
	 * @param string $provider       The AI provider to use.
	 * @return void Outputs streamed response directly.
	 */
	public function stream_chat_completion( $question, $context_chunks, $provider, $history = [] ) {

		/**
		 * Fires before the default stream completion handlers.
		 * Allows Pro add-on or other plugins to implement their own handlers.
		 * The hooked function should call exit() to prevent default execution.
		 *
		 * @param string $question       User's question.
		 * @param array  $context_chunks Relevant context chunks.
		 * @param array  $history        Conversation history.
		 */
		do_action( "fe_search_ai_stream_for_{$provider}", $question, $context_chunks, $history, $provider );

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
	 * FE_Search_AI_Logger when debug logging is enabled.
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
			$api_key = '';

			$encrypted_key = $this->options['provider']['openai_compatible_key'] ?? '';
			if ( is_string( $encrypted_key ) && '' !== $encrypted_key ) {
				$api_key = FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
			}

			if ( empty( $api_url ) || empty( $api_key ) ) {
				$pro_settings      = get_option( 'fe_search_ai_pro_settings', [] );
				$provider_settings = is_array( $pro_settings ) ? ( $pro_settings['provider'] ?? [] ) : [];

				if ( empty( $api_url ) && is_array( $provider_settings ) ) {
					$api_url = $provider_settings['openai_compatible_endpoint'] ?? $api_url;
				}

				if ( empty( $api_key ) && is_array( $provider_settings ) ) {
					$pro_encrypted_key = $provider_settings['openai_compatible_api_key'] ?? '';
					if ( is_string( $pro_encrypted_key ) && '' !== $pro_encrypted_key ) {
						$api_key = FE_Search_AI_Encryption_Helper::decrypt( $pro_encrypted_key );
					}
				}
			}
		} else {
			$api_url = 'https://api.openai.com/v1/chat/completions';
			// Admin UI stores the key at provider.openai_key.
			$encrypted_key = $this->options['provider']['openai_key'] ?? '';
			$api_key       = FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'INFO',
				'Chat completion endpoint resolved.',
				[
					'provider' => $provider,
					'endpoint' => $api_url,
					'has_key'  => ! empty( $api_key ),
					'is_pro'   => (bool) $this->is_license_active,
				]
			);
		}

		if ( empty( $api_key ) || empty( $api_url ) ) {
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'Chat completion failed: API Key or Endpoint URL is not set.',
				[ 'provider' => $provider ]
			);

			echo 'data: ' . json_encode( [ 'text' => __( 'Error: OpenAI API key not set.', 'fe-search-ai' ) ] ) . "\n\n";
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
		\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			'model'       => $model,
			'messages'    => $messages,
			'stream'      => true,
			'temperature' => 0.1, // RAG用に低い温度を設定
		];

		if ( 'openai_compatible' === $provider ) {
			$body['stream'] = false;

			// ローカルLLM用に実行時間制限を延長
			set_time_limit( 300 ); // 5分

			$response = wp_remote_post(
				$api_url,
				[
					'headers' => [
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					],
					'body'    => wp_json_encode( $body ),
					'timeout' => 300,
				]
			);

			$duration      = round( ( microtime( true ) - $start_time ) * 1000 );
			$http_status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$response_body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

			if ( is_wp_error( $response ) ) {
				\FESearchAI\Core\FE_Search_AI_Logger::log(
					'ERROR',
					'Chat completion failed (OpenAI-Compatible, WP HTTP Error).',
					[
						'provider'      => $provider,
						'model'         => $model,
						'error_message' => $response->get_error_message(),
						'duration_ms'   => $duration,
					]
				);
				echo 'data: ' . json_encode( [ 'text' => __( 'Error: Connection failed.', 'fe-search-ai' ) ] ) . "\n\n";
				echo "data: [DONE]\n\n";
				flush();
				return;
			}

			if ( 200 !== $http_status ) {
				\FESearchAI\Core\FE_Search_AI_Logger::log(
					'ERROR',
					'Chat completion failed (OpenAI-Compatible, API Error).',
					[
						'provider'    => $provider,
						'model'       => $model,
						'http_status' => $http_status,
						'duration_ms' => $duration,
					]
				);
				echo 'data: ' . json_encode( [ 'text' => __( 'Error: API request failed.', 'fe-search-ai' ) ] ) . "\n\n";
				echo "data: [DONE]\n\n";
				flush();
				return;
			}

			$content = '';
			if ( is_array( $response_body ) ) {
				if ( isset( $response_body['choices'][0]['message']['content'] ) ) {
					$content = (string) $response_body['choices'][0]['message']['content'];
				} elseif ( isset( $response_body['choices'][0]['text'] ) ) {
					$content = (string) $response_body['choices'][0]['text'];
				}
			}

			$content = apply_filters( 'fe_search_ai_filter_model_response', $content );
			echo 'data: ' . json_encode( [ 'text' => $content ] ) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

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
							$content = apply_filters( 'fe_search_ai_filter_model_response', $chunk['choices'][0]['delta']['content'] );

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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			'[FE Search AI] Rate limit reached: %s',
			$context
		);

		$body  = "A rate limit error occurred in FE Search AI.\n\n";
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
	 * using FE_Search_AI_Logger when debug logging is enabled.
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
		$encrypted_key = $this->options['provider']['google_key'] ?? '';
		$api_key       = FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( empty( $api_key ) ) {
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'Chat completion failed: API Key or Endpoint URL is not set.',
				[ 'provider' => $provider ]
			);

			echo 'data: ' . json_encode( [ 'text' => __( 'Error: Google API key is not set.', 'fe-search-ai' ) ] ) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$model = 'gemini-2.5-flash-lite'; // Default
		if ( $this->is_license_active ) {
			// Read Gemini model settings from Pro options (fe_search_ai_pro_settings).
			$pro_settings   = get_option( 'fe_search_ai_pro_settings', [] );
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
		\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			'generationConfig'  => [
				'temperature' => 0.1, // RAG用に低い温度を設定
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
							$content = apply_filters( 'fe_search_ai_filter_model_response', $content );

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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
	 * using FE_Search_AI_Logger when debug logging is enabled.
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
		$encrypted_key = $this->options['provider']['anthropic_key'] ?? '';
		$api_key       = FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( empty( $api_key ) ) {
			\FESearchAI\Core\FE_Search_AI_Logger::log(
				'ERROR',
				'Chat completion failed: API Key or Endpoint URL is not set.',
				[ 'provider' => $provider ]
			);

			echo 'data: ' . json_encode(
				[ 'text' => __( 'Error: Anthropic API key not set.', 'fe-search-ai' ) ]
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
		\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			'model'       => $model,
			'max_tokens'  => 4096,
			'messages'    => $messages['messages'],
			'system'      => $messages['system'],
			'stream'      => true,
			'temperature' => 0.1, // RAG用に低い温度を設定
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
							$content = apply_filters( 'fe_search_ai_filter_model_response', $content );

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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
		$custom_prompts = get_option( 'fe_search_ai_custom_prompts', [] );
		$free_prompt    = $custom_prompts['system_prompt'] ?? '';
		if ( ! empty( $free_prompt ) ) {
			$system_prompt = $free_prompt;
		}

		// If the Pro version is enabled and there are model-specific settings, they will overwrite accordingly.
		if ( $this->is_license_active ) {
			$model_specific_prompts = $custom_prompts['custom_prompts'] ?? [];

			if ( ! empty( $model_specific_prompts[ $provider ] ) ) {
				$system_prompt = $model_specific_prompts[ $provider ];
			}
		}

		// Get site info for placeholders (but don't replace yet)
		$site_info    = get_option( 'fe_search_ai_site_info', [] );
		$site_name    = $site_info['site_name'] ?? get_bloginfo( 'name' );
		$site_purpose = $site_info['site_purpose'] ?? get_bloginfo( 'description' );
		$site_url     = home_url( '/' );

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

		// Add Gemini-specific constraints to prevent hallucination
		// @TODO: Temporarily commented out for testing
		/*
		if ( $provider === 'google' ) {
			$system_prompt .= "\n\nGEMINI INSTRUCTIONS:\n" .
				"You MUST only use the job postings listed under 'REAL JOB POSTINGS FROM THE WEBSITE'.\n" .
				"Do NOT create any fake job information.\n" .
				"Use the exact URLs provided.\n" .
				"If no relevant jobs exist, say: 申し訳ありませんが、その情報は見つかりませんでした。サイト内で関連情報をお探しいただけますでしょうか？\n" .
				"Follow the response format examples provided below.\n";
		}
		*/

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
		$system_prompt = apply_filters( 'fe_search_ai_system_prompt', $system_prompt, $provider );

		// Initialize context string.
		$context_str = '';

		// Debug: Log incoming context chunks
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FE AI Search - Incoming context chunks: ' . print_r( $context_chunks, true ) );
			error_log( 'FE AI Search - Number of context chunks: ' . count( $context_chunks ) );
		}

		// Legal links are managed under the Display > Text/Links settings.
		$links           = $this->options['display']['links'] ?? [];
		$terms_page_id   = $links['terms_page_id'] ?? 0;
		$privacy_page_id = $links['privacy_page_id'] ?? 0;

		// Build legal document context.
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

		// Construct search results context from content chunks.
		if ( ! empty( $context_chunks ) ) {
			foreach ( $context_chunks as $chunk ) {
				$post_id    = $chunk['post_id'] ?? 0;
				$post_title = $chunk['title'] ?? 'Untitled';
				$post_date  = '';
				$metadata   = [];

				// Get post data if we have a valid ID
				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$post_date = date( 'Y-m-d', strtotime( $post->post_date ) );

						// Get taxonomy metadata
						$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
						foreach ( $taxonomies as $taxonomy ) {
							if ( $taxonomy->public ) {
								$terms = get_the_terms( $post_id, $taxonomy->name );
								if ( $terms && ! is_wp_error( $terms ) ) {
									foreach ( $terms as $term ) {
										$metadata[] = '[' . $taxonomy->label . ':' . $term->name . ']';
									}
								}
							}
						}
					}
				}

				// Build compact format
				$context_str .= "ID: {$post_id}\n";
				$context_str .= "Title: {$post_title}\n";
				$context_str .= "URL: {$chunk['permalink']}\n";
				$context_str .= "Date: {$post_date}\n";
				if ( ! empty( $metadata ) ) {
					$context_str .= 'Metadata: ' . implode( '', $metadata ) . "\n";
				}
				$context_str .= "Content:\n{$chunk['content_chunk']}\n\n";
			}
		}

		// Append legal documents after search results, if any.
		if ( ! empty( $legal_context ) ) {
			$context_str .= "\n--- Legal Documents ---\n" . $legal_context;
		}

		// Ensure there is content when no context exists.
		if ( '' === $context_str ) {
			$context_str = '[No relevant information found for this query]';
		}

		// Debug: Log final context string
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FE AI Search - Final context string: ' . $context_str );
		}

		// Now replace all placeholders with the constructed values
		$system_prompt = str_replace( '{site_name}', $site_name, $system_prompt );
		$system_prompt = str_replace( '{site_purpose}', $site_purpose, $system_prompt );
		$system_prompt = str_replace( '{site_url}', $site_url, $system_prompt );
		$system_prompt = str_replace( '{context_content}', $context_str, $system_prompt );
		$system_prompt = str_replace( '{user_question}', $question, $system_prompt );

		// Debug: Log final system prompt after all replacements
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FE AI Search - Final system prompt after replacements: ' . $system_prompt );
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

		// Initialize final messages array
		$final_messages = [];

		// Debug: Log the context being sent to AI
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FE AI Search - Context sent to AI: ' . $context_str );
			error_log( 'FE AI Search - Number of context chunks: ' . count( $context_chunks ) );
		}

		// Add conversation history
		foreach ( $messages as $msg ) {
			$final_messages[] = $msg;
		}

		// Add the complete prompt as system message
		$final_messages[] = [
			'role'    => 'system',
			'content' => $system_prompt,
		];

		// Return the final data in the format specified by the provider.
		if ( $for_claude ) {
			return [ 'messages' => array_values( $final_messages ), 'system' => $system_prompt ];
		} else { // for OpenAI & Gemini
			return $final_messages;
		}
	}

	/**
	 * Returns the default system prompt.
	 *
	 * @return string
	 */
	public static function get_default_system_prompt() {
		return "Role:
You are a dedicated \"Job Search Assistant\" for the website \"{site_name}\".
Your goal is to answer user queries strictly based on the \"Search Results\" provided below.

### Site Profile
- Name: {site_name}
- URL: {site_url}
- Purpose: {site_purpose}

### User Query
{user_question}

### Search Results (Context data from the website)
{context_content}

### Critical Instructions
1. **Data Source Authority:**
   - You MUST answer solely based on the \"Search Results\" section above.
   - Ignore your internal training data regarding external jobs or general knowledge.
   - Each search result includes ID, Title, URL, Date, Metadata, and Content fields.

2. **Handling Job Status (Important):**
   - The search results may contain jobs marked as \"募集終了\" in the Metadata field.
   - **You MUST display these items** if they match the user's query, but clearly state that recruitment has ended (e.g., add \"※募集終了\" to the title).
   - Do NOT hide content just because it is closed/old. The user uses this search to reference past data as well.

3. **Response Behavior:**
   - If relevant items are found, list them using Markdown bullets.
   - **Citation format:** Use the Title and URL from each search result to create links like `[Title](URL)`
   - **Metadata filtering:** If the user asks for specific conditions (e.g., \"Tokyo\"), filter based on the Metadata fields. For example:
     - Location: Look for [地域:東京都] in Metadata
     - Salary: Look for [月収:～50万] in Metadata
     - Employment Type: Look for [雇用形態:正社員] in Metadata
   - Include key information from Metadata (location, salary, employment type) and relevant Content details in your summary.

4. **Negative Response:**
   - If the \"Search Results\" section is empty or contains NO matches for the user's specific intent, output EXACTLY:
     \"申し訳ありませんが、その情報は見つかりませんでした。サイト内で関連情報をお探しいただけますでしょうか？\"

5. **Language:**
   - Use Japanese for the response.";
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
	 * @hook     wp_ajax_fe_search_ai_log_query
	 * @hook     wp_ajax_nopriv_fe_search_ai_log_query
	 */
	public function ajax_log_query() {
		// Use the same "Debug Mode" flag as the system logs (stored in fe_search_ai_settings[advanced][debug_mode]).
		$settings      = get_option( 'fe_search_ai_settings', [] );
		$advanced      = $settings['advanced'] ?? [];
		$debug_mode_on = ! empty( $advanced['debug_mode'] );
		$is_pro_active = class_exists( 'FESearchAI\\Pro\\Admin\\FE_Search_AI_Pro_Settings' );
		if ( ! $is_pro_active || ! $debug_mode_on ) {
			wp_send_json_success( [ 'log_id' => 0 ] );
			return;
		}

		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		global $wpdb;
		$logs_table = $wpdb->prefix . 'fe_search_ai_logs';

		$question = isset( $_POST['question'] ) ? sanitize_text_field( $_POST['question'] ) : '';
		$answer   = isset( $_POST['answer'] ) ? wp_kses_post( $_POST['answer'] ) : '';

		// Apply personal data filter before logging, as an additional safety net.
		$question      = $this->filter_personal_data( $question );
		$answer        = $this->filter_personal_data( $answer );
		$session_id    = isset( $_POST['session_id'] ) ? sanitize_key( $_POST['session_id'] ) : '';
		$context_found = isset( $_POST['context_found'] ) ? (bool) $_POST['context_found'] : false;
		$question_len  = isset( $_POST['question_length'] ) ? intval( $_POST['question_length'] ) : 0;
		$log_id        = 0;

		// Check if question logging is enabled via constant or filter
		$enable_question_logging = (
			defined( 'FE_SEARCH_AI_LOG_QUESTIONS' ) && FE_SEARCH_AI_LOG_QUESTIONS
		) || apply_filters( 'fe_search_ai_enable_question_logging', false );

		// For privacy protection, do not store the full question text by default.
		// Only create a log entry when both a session ID and an answer are available.
		if ( ! empty( $session_id ) && ! empty( $answer ) ) {
			// Store actual question text if logging is enabled, otherwise store length info only
			$question_text = $enable_question_logging ? $question : sprintf( 'User question is not logged. (length: %d chars)', max( 0, $question_len ) );

			$wpdb->insert(
				$logs_table,
				[
					'session_id'    => $session_id,
					'question'      => $question_text,
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

	 * Records a simple INFO-level event in the {prefix}fe_search_ai_system_logs table
	 * when the user accepts the privacy consent overlay in the frontend chat UI.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_log_consent() {
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
		$source     = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'chat_overlay';

		\FESearchAI\Core\FE_Search_AI_Logger::log(
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
	 * AJAX handler: Retrieves session logs with rating status for restoring feedback buttons.
	 *
	 * Returns logs for the given session ID including rating information so that
	 * feedback buttons can be restored when the chat UI is reopened.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_get_session_logs() {
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error( __( 'Session ID is required.', 'fe-search-ai' ) );
			return;
		}

		global $wpdb;
		$logs_table = $wpdb->prefix . 'fe_search_ai_logs';

		// Get logs for this session with rating information
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, question, answer, rating, created_at 
			 FROM {$logs_table} 
			 WHERE session_id = %s 
			 ORDER BY created_at ASC",
				$session_id
			)
		);

		wp_send_json_success( [ 'logs' => $logs ] );
	}

	/**
	 * AJAX handler: Stores a user feedback rating for a conversation log entry.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_rate_answer() {
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$rating = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;

		if ( $log_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Log ID is required.', 'fe-search-ai' ) ] );
			return;
		}

		if ( ! in_array( $rating, [ -1, 0, 1 ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid rating.', 'fe-search-ai' ) ] );
			return;
		}

		global $wpdb;
		$logs_table = $wpdb->prefix . 'fe_search_ai_logs';

		$updated = $wpdb->update(
			$logs_table,
			[ 'rating' => $rating ],
			[ 'id' => $log_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save rating.', 'fe-search-ai' ) ] );
			return;
		}

		wp_send_json_success();
	}

	/**
	 * Handles the AJAX request to test an AI provider's API key.
	 *
	 * This method receives a provider slug and an API key. It attempts to
	 * authenticate against the provider's endpoint.
	 *
	 * It first checks a filter ('fe_search_ai_handle_custom_api_test') to allow
	 * add-ons (like the Pro version) to handle their own provider tests.
	 * If not handled by an add-on, it proceeds to test the built-in
	 * providers (OpenAI, Google, Anthropic).
	 *
	 * It sends a JSON response indicating success or failure.
	 *
	 * @since 1.0.0
	 * @hook wp_ajax_fe_search_ai_test_api_key
	 */
	public function ajax_test_api_key() {
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );
		$provider = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';

		if ( empty( $provider ) ) {
			wp_send_json_error( __( 'Provider missing.', 'fe-search-ai' ) );
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
		$result = apply_filters( 'fe_search_ai_handle_custom_api_test', $result, $provider, $api_key );

		if ( is_array( $result ) ) {

			$is_valid      = $result['is_valid'];
			$error_message = $result['message'];

		} else {

			$is_valid      = false;
			$error_message = '';

			switch ( $provider ) {
				case 'openai':
					if ( empty( $api_key ) ) {
						$error_message = __( 'API key is missing.', 'fe-search-ai' );
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
						$error_message = is_wp_error( $response ) ? $response->get_error_message() : __( 'Authentication failed.', 'fe-search-ai' );
					}
					break;

				case 'google':
					if ( empty( $api_key ) ) {
						$error_message = __( 'API key is missing.', 'fe-search-ai' );
						break;
					}
					$api_url  = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
					$response = wp_remote_get( $api_url );

					if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
						$is_valid = true;
					} else {
						$error_message = __( 'Authentication failed.', 'fe-search-ai' );
						if ( ! is_wp_error( $response ) ) {
							$body          = json_decode( wp_remote_retrieve_body( $response ), true );
							$error_message = $body['error']['message'] ?? $error_message;
						}
					}
					break;

				case 'anthropic':
					if ( empty( $api_key ) ) {
						$error_message = __( 'API key is missing.', 'fe-search-ai' );
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
						$error_message = __( 'Authentication failed.', 'fe-search-ai' );
						if ( ! is_wp_error( $response ) ) {
							$body          = json_decode( wp_remote_retrieve_body( $response ), true );
							$error_message = $body['error']['message'] ?? $error_message;
						}
					}
					break;

				default:
					$error_message = __( 'Unknown provider.', 'fe-search-ai' );
			}
			$result = [ 'is_valid' => $is_valid, 'message' => $error_message ];
		}

		if ( $result['is_valid'] ) {
			wp_send_json_success( '<span style="color: green;">✔ ' . __( 'Connection successful', 'fe-search-ai' ) . '</span>' );
		} else {
			wp_send_json_error( '<span style="color: red;">✖ ' . __( 'Connection failed', 'fe-search-ai' ) . ': ' . $result['message'] . '</span>' );
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
		$i18n_dir              = FE_SEARCH_AI_PLUGIN_DIR . 'includes/i18n/';

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
		$filtered_text = str_ireplace( $all_injection_phrases, __( '[REDACTED]', 'fe-search-ai' ), $text );

		// SECURITY: Log if a basic security filter was triggered.
		if ( $filtered_text !== $text ) {
			\FESearchAI\Core\FE_Search_AI_Logger::log(
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
		$patterns = apply_filters( 'fe_search_ai_personal_data_patterns', $patterns );

		$filtered = preg_replace( $patterns, __( '[REDACTED]', 'fe-search-ai' ), $text );

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
	 * single master settings array ('fe_search_ai_settings') with the new
	 * license key, status, and data.
	 *
	 * @since 1.0.0
	 * @hook wp_ajax_fe_search_ai_manage_license
	 */
	public function ajax_manage_license() {
		check_ajax_referer( 'fe_search_ai_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( $_POST['license_key'] ) : '';
		$action      = isset( $_POST['license_action'] ) ? sanitize_key( $_POST['license_action'] ) : '';

		if ( empty( $license_key ) || empty( $action ) ) {
			wp_send_json_error( [ 'message' => 'Missing key or action.' ] );
		}

		// Perform the license action.
		$handler = new \FESearchAI\Core\FE_Search_AI_License_Handler();
		$result  = ( 'activate' === $action ) ? $handler->activate( $license_key ) : $handler->deactivate( $license_key );

		// Determine the product ID from the remote response. If it's missing,
		// fall back to the main Pro product ID.
		$product_id = 0;
		if ( isset( $result['data']['data']['productId'] ) ) {
			$product_id = (int) $result['data']['data']['productId'];
		} elseif ( isset( $result['data']['productId'] ) ) {
			$product_id = (int) $result['data']['productId'];
		} elseif ( class_exists( '\\FESearchAI\\Core\\FE_Search_AI_License' ) ) {
			$product_id = \FESearchAI\Core\FE_Search_AI_License::PRODUCT_ID_PRO;
		}

		$all_licenses = get_option( 'fe_search_ai_license', [] );

		// Ensure we have a proper array, handle legacy string format
		if ( is_string( $all_licenses ) ) {
			$all_licenses = maybe_unserialize( $all_licenses );
		}

		if ( ! is_array( $all_licenses ) ) {
			$all_licenses = [];
		}

		if ( ! isset( $all_licenses['products'] ) || ! is_array( $all_licenses['products'] ) ) {
			$all_licenses['products'] = [];
		}

		if ( $result && $result['success'] ) {
			// Determine the local license status based on the requested action.
			// For this site, a successful deactivation should always be treated as inactive,
			// even if the remote license itself remains valid for other activations.
			$local_status = ( 'activate' === $action ) ? 'active' : 'inactive';

			if ( $product_id > 0 ) {
				// Debug: Log encryption attempt
				$encrypted_key = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $license_key );
				error_log( 'FE AI Search: License key encryption test - Original: ' . substr( $license_key, 0, 10 ) . '... Encrypted: ' . substr( $encrypted_key, 0, 20 ) . '...' );

				$all_licenses['products'][ $product_id ] = [
					'key'    => $encrypted_key,
					'status' => $local_status,
					'data'   => $result['data'] ?? [],
				];
			}

			update_option( 'fe_search_ai_license', $all_licenses );
			delete_transient( 'fe_search_ai_license_error' );
			$send_response = 'wp_send_json_success';

		} else {
			if ( $product_id > 0 ) {
				$all_licenses['products'][ $product_id ] = [
					'key'    => \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $license_key ),
					'status' => 'inactive',
					'data'   => $result['data'] ?? [],
				];
			}

			update_option( 'fe_search_ai_license', $all_licenses );
			set_transient( 'fe_search_ai_license_error', $result['message'], 60 );
			$send_response = 'wp_send_json_error';
		}

		// Send the final JSON response.
		$send_response( [ 'message' => $result['message'] ] );
	}
}
