<?php
/**
 * Retrieval trace recorder skeleton.
 *
 * @package    fe-search-ai
 * @subpackage Core
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a guarded write path for future retrieval trace persistence.
 *
 * @since 1.0.0
 */
class FE_Search_AI_Retrieval_Trace_Recorder {

	/**
	 * Register recorder hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		add_action( 'fe_search_ai_retrieval_trace', [ __CLASS__, 'record' ] );
	}

	/**
	 * Record a retrieval trace when persistence is explicitly enabled.
	 *
	 * @since 1.0.0
	 * @param array $trace Safe retrieval trace payload.
	 * @return bool True when recorded, false otherwise.
	 */
	public static function record( $trace ) {
		if ( ! is_array( $trace ) ) {
			return false;
		}

		/**
		 * Filters whether retrieval trace persistence is enabled.
		 *
		 * @since 1.0.0
		 * @param bool  $enabled Whether persistence is enabled.
		 * @param array $trace   Safe retrieval trace payload.
		 */
		$enabled = (bool) apply_filters( 'fe_search_ai_enable_retrieval_trace_persistence', false, $trace );
		if ( ! $enabled ) {
			return false;
		}

		return self::insert_trace( self::normalize_trace( $trace ) );
	}

	/**
	 * Get the trace header table name.
	 *
	 * @since 1.0.0
	 * @return string Table name.
	 */
	public static function traces_table() {
		global $wpdb;
		return $wpdb->prefix . 'fe_search_ai_retrieval_traces';
	}

	/**
	 * Get the trace item table name.
	 *
	 * @since 1.0.0
	 * @return string Table name.
	 */
	public static function trace_items_table() {
		global $wpdb;
		return $wpdb->prefix . 'fe_search_ai_retrieval_trace_items';
	}

	/**
	 * Normalize a trace payload before persistence.
	 *
	 * @since 1.0.0
	 * @param array $trace Safe retrieval trace payload.
	 * @return array Normalized trace payload.
	 */
	public static function normalize_trace( array $trace ) {
		$trace['trace_id']     = isset( $trace['trace_id'] ) ? sanitize_text_field( (string) $trace['trace_id'] ) : '';
		$trace['sequence_id']  = isset( $trace['sequence_id'] ) ? sanitize_text_field( (string) $trace['sequence_id'] ) : '';
		$trace['query_hash']   = isset( $trace['query_hash'] ) ? sanitize_text_field( (string) $trace['query_hash'] ) : '';
		$trace['query_length'] = isset( $trace['query_length'] ) ? max( 0, (int) $trace['query_length'] ) : 0;
		$trace['items']        = isset( $trace['items'] ) && is_array( $trace['items'] ) ? array_values( $trace['items'] ) : [];

		return $trace;
	}

	/**
	 * Insert a normalized trace payload.
	 *
	 * @since 1.0.0
	 * @param array $trace Normalized trace payload.
	 * @return bool True when inserted, false otherwise.
	 */
	private static function insert_trace( array $trace ) {
		global $wpdb;

		if ( empty( $trace['trace_id'] ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			self::traces_table(),
			[
				'trace_id'        => $trace['trace_id'],
				'sequence_id'     => $trace['sequence_id'],
				'query_hash'      => $trace['query_hash'],
				'query_length'    => $trace['query_length'],
				'pipeline'        => wp_json_encode( $trace['pipeline'] ?? [] ),
				'candidate_count' => isset( $trace['candidate_count'] ) ? (int) $trace['candidate_count'] : count( $trace['items'] ),
				'payload'         => wp_json_encode( $trace ),
				'created_at'      => current_time( 'mysql', true ),
			],
			[
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
			]
		);

		if ( false === $inserted ) {
			return false;
		}

		foreach ( $trace['items'] as $item ) {
			self::insert_trace_item( $trace['trace_id'], is_array( $item ) ? $item : [] );
		}

		return true;
	}

	/**
	 * Insert a single trace item.
	 *
	 * @since 1.0.0
	 * @param string $trace_id Trace ID.
	 * @param array  $item     Trace item.
	 * @return bool True when inserted, false otherwise.
	 */
	private static function insert_trace_item( $trace_id, array $item ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::trace_items_table(),
			[
				'trace_id'   => (string) $trace_id,
				'final_rank' => isset( $item['final_rank'] ) ? (int) $item['final_rank'] : 0,
				'post_id'    => isset( $item['post_id'] ) ? (int) $item['post_id'] : 0,
				'chunk_hash' => isset( $item['chunk_hash'] ) ? (string) $item['chunk_hash'] : '',
				'source'     => isset( $item['source'] ) ? (string) $item['source'] : '',
				'scores'     => wp_json_encode( $item['scores'] ?? [] ),
				'ranks'      => wp_json_encode( $item['ranks'] ?? [] ),
				'payload'    => wp_json_encode( $item ),
				'created_at' => current_time( 'mysql', true ),
			],
			[
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			]
		);

		return false !== $inserted;
	}
}
