<?php

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cohere reranker implementation.
 *
 * This class plugs into the retrieved-chunks filter and optionally reranks
 * the candidate chunks using Cohere's Rerank API before the chunks are
 * injected into the LLM prompt.
 *
 * @package fe-search-ai
 * @since 0.9.0
 */
class FE_Search_AI_Cohere_Reranker {

	/**
	 * Check whether plugin debug mode is enabled.
	 *
	 * @since 0.9.0
	 * @return bool
	 */
	private static function is_debug_enabled() {
		$settings = get_option( 'fe_search_ai_settings', [] );
		$advanced = isset( $settings['advanced'] ) && is_array( $settings['advanced'] ) ? $settings['advanced'] : [];
		return ! empty( $advanced['debug_mode'] );
	}

	/**
	 * Build debug payload items from chunks.
	 *
	 * @since 0.9.0
	 * @param array $chunks Chunks.
	 * @return array
	 */
	private static function build_debug_items( $chunks ) {
		$items = [];
		foreach ( (array) $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}
			$items[] = [
				'post_id' => isset( $chunk['post_id'] ) ? (int) $chunk['post_id'] : 0,
				'title'   => isset( $chunk['title'] ) ? (string) $chunk['title'] : '',
				'url'     => isset( $chunk['permalink'] ) ? (string) $chunk['permalink'] : '',
			];
		}
		return $items;
	}

	/**
	 * Register filter hooks.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function register() {
		add_filter( 'fe_search_ai_retrieved_chunks', [ __CLASS__, 'maybe_rerank_chunks' ], 20, 2 );
	}

	/**
	 * Conditionally rerank retrieved chunks.
	 *
	 * The feature is disabled by default. When enabled, it calls the Cohere Rerank API
	 * and returns the top N chunks in Cohere score order.
	 *
	 * Fallback behavior:
	 * - If reranking is disabled or misconfigured, returns the original chunks.
	 * - If the API call fails, returns the original chunks trimmed to top N.
	 *
	 * @since 0.9.0
	 * @param array  $chunks   Retrieved chunks.
	 * @param string $question User question.
	 * @return array
	 */
	public static function maybe_rerank_chunks( $chunks, $question ) {
		// Get sequence ID from global context
		$sequence_id = '';
		if ( isset( $GLOBALS['fe_search_ai_current_sequence_id'] ) ) {
			$sequence_id = $GLOBALS['fe_search_ai_current_sequence_id'];
		}

		if ( ! is_array( $chunks ) || empty( $chunks ) ) {
			return $chunks;
		}

		$settings = get_option( 'fe_search_ai_settings', [] );
		$rerank   = isset( $settings['rerank'] ) && is_array( $settings['rerank'] ) ? $settings['rerank'] : [];

		$enabled = ! empty( $rerank['enabled'] );
		$top_n   = isset( $rerank['top_n'] ) ? (int) $rerank['top_n'] : 5;
		if ( $top_n <= 0 ) {
			$top_n = 5;
		}

		if ( ! $enabled ) {
			$trimmed = array_slice( $chunks, 0, $top_n );
			if ( self::is_debug_enabled() ) {
				FE_Search_AI_Logger::log(
					'DEBUG',
					'Reranker is disabled. Returning trimmed chunks in original order.',
					[ 'candidates' => count( $chunks ), 'returned' => count( $trimmed ), 'top_n' => $top_n, 'items' => self::build_debug_items( $trimmed ) ]
				);
			}
			return $trimmed;
		}

		$provider      = isset( $settings['provider'] ) && is_array( $settings['provider'] ) ? $settings['provider'] : [];
		$encrypted_key = $provider['cohere_key'] ?? ( $rerank['cohere_api_key'] ?? '' );
		$api_key       = FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		if ( '' === trim( (string) $api_key ) ) {
			$trimmed = array_slice( $chunks, 0, $top_n );
			if ( self::is_debug_enabled() ) {
				FE_Search_AI_Logger::log(
					'DEBUG',
					'Reranker is enabled but Cohere API key is empty. Returning trimmed chunks in original order.',
					[ 'candidates' => count( $chunks ), 'returned' => count( $trimmed ), 'top_n' => $top_n, 'items' => self::build_debug_items( $trimmed ) ]
				);
			}
			return $trimmed;
		}

		$timeout = isset( $rerank['timeout_sec'] ) ? (int) $rerank['timeout_sec'] : 15;
		if ( $timeout <= 0 ) {
			$timeout = 15;
		}
		$model = 'rerank-v3.5';
		if ( class_exists( '\\FESearchAI\\Core\\FE_Search_AI_License' ) && FE_Search_AI_License::is_pro_active() ) {
			$pro_settings   = get_option( 'fe_search_ai_pro_settings', [] );
			$model_settings = $pro_settings['model']['cohere_model'] ?? [];
			$model_type     = $model_settings['type'] ?? '';
			$custom_model   = $model_settings['custom'] ?? '';
			if ( 'custom' === $model_type && '' !== trim( (string) $custom_model ) ) {
				$model = sanitize_text_field( $custom_model );
			} elseif ( '' !== trim( (string) $model_type ) ) {
				$model = sanitize_text_field( $model_type );
			}
		}

		// Build documents list.
		$documents = [];
		$map       = [];
		foreach ( $chunks as $idx => $chunk ) {
			$summary_text = isset( $chunk['summary_text'] ) ? (string) $chunk['summary_text'] : '';
			$text         = '' !== trim( $summary_text )
				? $summary_text
				: ( isset( $chunk['content_chunk'] ) ? (string) $chunk['content_chunk'] : '' );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$documents[] = $text;
			$map[]       = $idx;
		}

		if ( empty( $documents ) ) {
			$trimmed = array_slice( $chunks, 0, $top_n );
			if ( self::is_debug_enabled() ) {
				FE_Search_AI_Logger::log(
					'DEBUG',
					'No documents available for reranking. Returning trimmed chunks in original order.',
					[ 'candidates' => count( $chunks ), 'returned' => count( $trimmed ), 'top_n' => $top_n, 'items' => self::build_debug_items( $trimmed ) ]
				);
			}
			return $trimmed;
		}

		$cache_key = 'fe_search_ai_rerank_' . md5( $model . '|' . (string) $question . '|' . wp_json_encode( $documents ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$trimmed = array_slice( $cached, 0, $top_n );
			if ( self::is_debug_enabled() ) {
				FE_Search_AI_Logger::log(
					'DEBUG',
					'Reranker cache hit. Returning cached reranked chunks.',
					[ 'returned' => count( $trimmed ), 'top_n' => $top_n ]
				);
			}
			return $trimmed;
		}

		$start       = microtime( true );
		$result      = self::request_rerank( $api_key, $question, $documents, $timeout, $model );
		$duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			FE_Search_AI_Logger::log(
				'ERROR',
				'Cohere rerank failed. Falling back to original order.',
				[ 'error' => $result->get_error_message(), 'duration_ms' => $duration_ms ]
			);
			$trimmed = array_slice( $chunks, 0, $top_n );
			if ( self::is_debug_enabled() ) {
				FE_Search_AI_Logger::log(
					'DEBUG',
					'Reranker fallback executed. Returning trimmed chunks in original order.',
					[ 'candidates' => count( $chunks ), 'returned' => count( $trimmed ), 'top_n' => $top_n, 'items' => self::build_debug_items( $trimmed ) ]
				);
			}
			return $trimmed;
		}

		$reranked_chunks = [];
		foreach ( $result as $item ) {
			$rank_index = isset( $item['index'] ) ? (int) $item['index'] : -1;
			if ( ! isset( $map[ $rank_index ] ) ) {
				continue;
			}
			$original_idx = $map[ $rank_index ];
			if ( ! isset( $chunks[ $original_idx ] ) ) {
				continue;
			}
			$reranked_chunks[] = $chunks[ $original_idx ];
			if ( count( $reranked_chunks ) >= $top_n ) {
				break;
			}
		}

		if ( empty( $reranked_chunks ) ) {
			$trimmed = array_slice( $chunks, 0, $top_n );
			if ( self::is_debug_enabled() ) {
				FE_Search_AI_Logger::log(
					'DEBUG',
					'Reranker returned no usable results. Returning trimmed chunks in original order.',
					[ 'candidates' => count( $chunks ), 'returned' => count( $trimmed ), 'top_n' => $top_n, 'items' => self::build_debug_items( $trimmed ) ]
				);
			}
			return $trimmed;
		}

		set_transient( $cache_key, $reranked_chunks, 10 * MINUTE_IN_SECONDS );

		FE_Search_AI_Logger::log_with_sequence(
			'INFO',
			'Cohere rerank succeeded.',
			[ 'candidates' => count( $chunks ), 'documents' => count( $documents ), 'top_n' => $top_n, 'model' => $model, 'duration_ms' => $duration_ms ],
			$sequence_id
		);

		return $reranked_chunks;
	}

	/**
	 * Call Cohere Rerank API.
	 *
	 * @since 0.9.0
	 * @param string $api_key   Cohere API key.
	 * @param string $question  Query string.
	 * @param array  $documents Document strings.
	 * @param int    $timeout   Request timeout.
	 * @param string $model     Cohere rerank model.
	 * @return array|\WP_Error
	 */
	private static function request_rerank( $api_key, $question, $documents, $timeout, $model = 'rerank-v3.5' ) {
		$body = [
			'model'            => $model,
			'query'            => (string) $question,
			'documents'        => array_values( $documents ),
			'top_n'            => min( 50, count( $documents ) ),
			'return_documents' => false,
		];

		$response = wp_remote_post(
			'https://api.cohere.com/v1/rerank',
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new \WP_Error( 'cohere_rerank_error', 'Cohere API returned non-2xx status.' );
		}

		$results = $data['results'] ?? null;
		if ( ! is_array( $results ) ) {
			return new \WP_Error( 'cohere_rerank_error', 'Cohere response is invalid.' );
		}

		$out = [];
		foreach ( $results as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['index'] ) ) {
				continue;
			}
			$out[] = [
				'index' => (int) $item['index'],
				'score' => isset( $item['relevance_score'] ) ? (float) $item['relevance_score'] : 0.0,
			];
		}

		return $out;
	}
}
