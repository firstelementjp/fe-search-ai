<?php
/**
 * Retrieval trace payload builder.
 *
 * @package    fe-search-ai
 * @subpackage Core
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds safe retrieval trace payloads for logs and future persistence.
 *
 * @since 1.0.0
 */
class FE_Search_AI_Retrieval_Trace {

	/**
	 * Build a trace payload from retrieved chunks.
	 *
	 * @since 1.0.0
	 * @param array  $chunks      Retrieved chunks.
	 * @param string $question    User question.
	 * @param string $sequence_id Log sequence ID.
	 * @param array  $metadata    Additional trace metadata.
	 * @return array Safe trace payload.
	 */
	public static function build_payload( array $chunks, $question, $sequence_id = '', array $metadata = [] ) {
		$metadata = array_merge(
			self::sanitize_metadata( $metadata ),
			self::build_metadata( $chunks )
		);

		$payload = [
			'trace_id'        => self::generate_trace_id( $sequence_id, $question ),
			'sequence_id'     => (string) $sequence_id,
			'query_hash'      => hash( 'sha256', (string) $question ),
			'query_length'    => strlen( (string) $question ),
			'candidate_count' => count( $chunks ),
			'pipeline'        => self::detect_pipeline( $chunks ),
			'items'           => self::build_items( $chunks ),
		];

		if ( ! empty( $metadata ) ) {
			$payload['metadata'] = $metadata;
		}

		/**
		 * Filters the retrieval trace payload before it is logged or dispatched.
		 *
		 * @since 1.0.0
		 * @param array  $payload     Safe retrieval trace payload.
		 * @param array  $chunks      Retrieved chunks.
		 * @param string $question    User question.
		 * @param string $sequence_id Log sequence ID.
		 */
		return apply_filters( 'fe_search_ai_retrieval_trace_payload', $payload, $chunks, $question, $sequence_id );
	}

	/**
	 * Generate a stable trace ID for the current retrieval event.
	 *
	 * @since 1.0.0
	 * @param string $sequence_id Log sequence ID.
	 * @param string $question    User question.
	 * @return string Trace ID.
	 */
	private static function generate_trace_id( $sequence_id, $question ) {
		return 'rt_' . hash( 'sha256', (string) $sequence_id . '|' . (string) $question . '|' . microtime( true ) );
	}

	/**
	 * Build safe per-chunk trace items.
	 *
	 * @since 1.0.0
	 * @param array $chunks Retrieved chunks.
	 * @return array Trace items.
	 */
	private static function build_items( array $chunks ) {
		$items = [];
		foreach ( array_values( $chunks ) as $index => $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$items[] = [
				'final_rank'      => $index + 1,
				'post_id'         => isset( $chunk['post_id'] ) ? (int) $chunk['post_id'] : 0,
				'title'           => isset( $chunk['title'] ) ? (string) $chunk['title'] : '',
				'permalink_hash'  => isset( $chunk['permalink'] ) ? hash( 'sha256', (string) $chunk['permalink'] ) : '',
				'chunk_hash'      => isset( $chunk['content_chunk'] ) ? hash( 'sha256', (string) $chunk['content_chunk'] ) : '',
				'source'          => isset( $chunk['source'] ) ? (string) $chunk['source'] : '',
				'scores'          => self::extract_scores( $chunk ),
				'ranks'           => self::extract_ranks( $chunk ),
				'hybrid_sources'  => self::extract_hybrid_sources( $chunk ),
				'hybrid_rankings' => self::extract_hybrid_rankings( $chunk ),
			];
		}

		return $items;
	}

	/**
	 * Extract known retrieval scores.
	 *
	 * @since 1.0.0
	 * @param array $chunk Retrieved chunk.
	 * @return array Scores.
	 */
	private static function extract_scores( array $chunk ) {
		$score_keys = [
			'bm25_score',
			'qdrant_score',
			'hybrid_score',
			'cohere_relevance_score',
		];
		$scores     = [];

		foreach ( $score_keys as $key ) {
			if ( isset( $chunk[ $key ] ) && is_numeric( $chunk[ $key ] ) ) {
				$scores[ $key ] = (float) $chunk[ $key ];
			}
		}

		return $scores;
	}

	/**
	 * Extract known retrieval ranks.
	 *
	 * @since 1.0.0
	 * @param array $chunk Retrieved chunk.
	 * @return array Ranks.
	 */
	private static function extract_ranks( array $chunk ) {
		$rank_keys = [
			'bm25_rank',
			'qdrant_rank',
			'hybrid_rank',
			'cohere_rank',
		];
		$ranks     = [];

		foreach ( $rank_keys as $key ) {
			if ( isset( $chunk[ $key ] ) && is_numeric( $chunk[ $key ] ) ) {
				$ranks[ $key ] = (int) $chunk[ $key ];
			}
		}

		return $ranks;
	}

	/**
	 * Extract hybrid source flags.
	 *
	 * @since 1.0.0
	 * @param array $chunk Retrieved chunk.
	 * @return array Hybrid sources.
	 */
	private static function extract_hybrid_sources( array $chunk ) {
		if ( empty( $chunk['hybrid_sources'] ) || ! is_array( $chunk['hybrid_sources'] ) ) {
			return [];
		}

		$sources = [];
		foreach ( $chunk['hybrid_sources'] as $source => $enabled ) {
			$sources[ sanitize_key( (string) $source ) ] = (bool) $enabled;
		}

		return $sources;
	}

	/**
	 * Extract hybrid source rankings.
	 *
	 * @since 1.0.0
	 * @param array $chunk Retrieved chunk.
	 * @return array Hybrid rankings.
	 */
	private static function extract_hybrid_rankings( array $chunk ) {
		if ( empty( $chunk['hybrid_rankings'] ) || ! is_array( $chunk['hybrid_rankings'] ) ) {
			return [];
		}

		$rankings = [];
		foreach ( $chunk['hybrid_rankings'] as $source => $rank ) {
			if ( is_numeric( $rank ) ) {
				$rankings[ sanitize_key( (string) $source ) ] = (int) $rank;
			}
		}

		return $rankings;
	}

	/**
	 * Build safe automatically generated trace metadata.
	 *
	 * @since 1.0.0
	 * @param array $chunks Retrieved chunks.
	 * @return array Trace metadata.
	 */
	private static function build_metadata( array $chunks ) {
		return [
			'source_counts' => self::build_source_counts( $chunks ),
		];
	}

	/**
	 * Count retrieval sources represented in the final trace items.
	 *
	 * @since 1.0.0
	 * @param array $chunks Retrieved chunks.
	 * @return array Source counts.
	 */
	private static function build_source_counts( array $chunks ) {
		$counts = [
			'qdrant'  => 0,
			'keyword' => 0,
			'both'    => 0,
			'unknown' => 0,
		];

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$hybrid_sources = self::extract_hybrid_sources( $chunk );
			$has_qdrant     = ! empty( $hybrid_sources['qdrant'] ) || isset( $chunk['qdrant_score'] );
			$has_keyword    = ! empty( $hybrid_sources['keyword'] ) || isset( $chunk['bm25_score'] );

			if ( $has_qdrant && $has_keyword ) {
				++$counts['both'];
			} elseif ( $has_qdrant ) {
				++$counts['qdrant'];
			} elseif ( $has_keyword ) {
				++$counts['keyword'];
			} else {
				++$counts['unknown'];
			}
		}

		return $counts;
	}

	/**
	 * Detect which retrieval stages contributed to the current result set.
	 *
	 * @since 1.0.0
	 * @param array $chunks Retrieved chunks.
	 * @return array Pipeline stage flags.
	 */
	private static function detect_pipeline( array $chunks ) {
		$pipeline = [
			'bm25'   => false,
			'qdrant' => false,
			'hybrid' => false,
			'cohere' => false,
		];

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}
			$pipeline['bm25']   = $pipeline['bm25'] || isset( $chunk['bm25_score'] );
			$pipeline['qdrant'] = $pipeline['qdrant'] || isset( $chunk['qdrant_score'] );
			$pipeline['hybrid'] = $pipeline['hybrid'] || isset( $chunk['hybrid_score'] );
			$pipeline['cohere'] = $pipeline['cohere'] || isset( $chunk['cohere_relevance_score'] );
		}

		return $pipeline;
	}

	/**
	 * Sanitize optional trace metadata.
	 *
	 * @since 1.0.0
	 * @param array $metadata Additional metadata.
	 * @return array Sanitized metadata.
	 */
	private static function sanitize_metadata( array $metadata ) {
		$safe = [];
		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$safe[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$safe[ $key ] = self::sanitize_metadata( $value );
			}
		}

		return $safe;
	}
}
