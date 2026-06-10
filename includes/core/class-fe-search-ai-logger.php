<?php
/**
 * A dedicated helper class for system logging.
 *
 * @package    fe-search-ai
 * @subpackage Core
 * @since 0.9.0
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System logger utility class for FE AI Search.
 *
 * Provides a static interface for writing structured log entries to the
 * custom system logs table when debug mode is enabled.
 *
 * @since 0.9.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Logger {

	/**
	 * Static sequence counters for tracking processing order.
	 *
	 * @var array
	 */
	private static $sequence_counters = [];

	/**
	 * Records a system-level event to the database if debug mode is enabled.
	 *
	 * This is a static method so it can be called from anywhere in the plugin
	 * without needing to instantiate the class.
	 *
	 * @param string $level   The log level (e.g., 'INFO', 'WARNING', 'ERROR', 'DEBUG').
	 * @param string $message The main log message.
	 * @param array  $data    Optional. Additional data to store as JSON.
	 */
	public static function log( string $level, string $message, array $data = [] ) {
		// Read the latest settings directly because this is a static method.
		$options    = get_option( 'fe_search_ai_settings', [] );
		$is_enabled = $options['advanced']['debug_mode'] ?? false;
		if ( ! $is_enabled ) {
			return;
		}

		// Hook name is properly prefixed with fe_search_ai_.
		$should_log = apply_filters( 'fe_search_ai_allow_system_log_entry', true, $level, $message, $data );
		if ( ! $should_log ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';

		// Check if the table exists to prevent errors.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct query required for custom table check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// If a session identifier is provided via HTTP header, attach it to the log data
		// so that all chat-related system logs can be correlated by session.
		if ( ! isset( $data['session_id'] ) && ! empty( $_SERVER['HTTP_X_FE_AI_SESSION'] ) ) {
			$session_id = sanitize_key( wp_unslash( $_SERVER['HTTP_X_FE_AI_SESSION'] ) );
			if ( ! empty( $session_id ) ) {
				$data['session_id'] = $session_id;
			}
		}

		$forbidden_keys = [
			'question',
			'answer',
			'tokens',
			'token_list',
			'keywords',
			'keyword_list',
			'keywords_list',
			'keywords_searched',
			'context',
			'context_text',
			'chunks',
			'retrieved_chunks',
			'prompt',
			'system_prompt',
		];
		// Hook name is properly prefixed with fe_search_ai_.
		$forbidden_keys = apply_filters( 'fe_search_ai_system_log_forbidden_keys', $forbidden_keys, $level, $message, $data );
		foreach ( $forbidden_keys as $key ) {
			if ( isset( $data[ $key ] ) ) {
				unset( $data[ $key ] );
			}
		}

		// Hook name is properly prefixed with fe_search_ai_.
		$data = apply_filters( 'fe_search_ai_system_log_payload', $data, $level, $message );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// Direct insert required for custom table.
		$wpdb->insert(
			$table_name,
			[
				'level'      => sanitize_key( $level ),
				'message'    => $message,
				'extra_data' => wp_json_encode( $data ),
				'created_at' => current_time( 'mysql' ),
			]
		);
	}

	/**
	 * Records a system-level event with sequence tracking to the database if debug mode is enabled.
	 *
	 * This method extends the standard log() function to include sequence tracking for processing order.
	 * When a sequence_id is provided, it assigns a sequential order number to track the processing flow.
	 *
	 * @param string $level       The log level (e.g., 'INFO', 'WARNING', 'ERROR', 'DEBUG').
	 * @param string $message     The main log message.
	 * @param array  $data        Optional. Additional data to store as JSON.
	 * @param string $sequence_id Optional. Unique identifier for the processing sequence.
	 */
	public static function log_with_sequence( string $level, string $message, array $data = [], string $sequence_id = '' ) {
		if ( ! empty( $sequence_id ) ) {
			// Initialize sequence counter for this sequence_id if not exists
			if ( ! isset( self::$sequence_counters[ $sequence_id ] ) ) {
				self::$sequence_counters[ $sequence_id ] = 0;
			}

			// Increment and assign sequence order
			++self::$sequence_counters[ $sequence_id ];
			$data['sequence_id']    = $sequence_id;
			$data['sequence_order'] = self::$sequence_counters[ $sequence_id ];
		}

		self::log( $level, $message, $data );
	}

	/**
	 * Generates a unique sequence ID for tracking processing flow.
	 *
	 * @param string $prefix Optional prefix for the sequence ID.
	 * @return string Unique sequence identifier.
	 */
	public static function generate_sequence_id( string $prefix = 'process' ): string {
		return uniqid( $prefix . '_', true );
	}

	/**
	 * Deletes all system log entries from the custom logs table.
	 *
	 * This method is intended to be called from an admin-only context when
	 * the site owner explicitly requests a full log purge.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function clear_logs() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';

		// Check if the table exists before attempting to truncate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct query required for custom table check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// Use TRUNCATE to efficiently delete all rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Table name is interpolated but controlled internally.
		$wpdb->query( "TRUNCATE TABLE `{$table_name}`" );
	}

	/**
	 * Rotates system logs by removing old entries based on retention policy.
	 *
	 * This method is called by the daily cron event to automatically clean up
	 * old log entries and prevent the logs table from growing indefinitely.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function rotate_logs() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';

		// Check if the table exists before attempting to rotate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct query required for custom table check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// Default retention period: 30 days
		// Hook name is properly prefixed with fe_search_ai_.
		$retention_days = apply_filters( 'fe_search_ai_log_retention_days', 30 );
		$cutoff_ts      = time() - ( (int) $retention_days * DAY_IN_SECONDS );
		$cutoff_date    = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

		// Delete old log entries
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct query required for custom table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . esc_sql( $table_name ) . '` WHERE created_at < %s',
				$cutoff_date
			)
		);
	}

	/**
	 * Rotates conversation logs by removing old entries based on retention policy.
	 *
	 * This method is intended to be called by the daily cron event, similarly to
	 * rotate_logs(), but targets the conversation logs table.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function rotate_conversation_logs() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct query required for custom table check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}
		// Hook name is properly prefixed with fe_search_ai_.
		$retention_days = apply_filters( 'fe_search_ai_conversation_log_retention_days', 7 );
		$cutoff_ts      = time() - ( (int) $retention_days * DAY_IN_SECONDS );
		$cutoff_date    = gmdate( 'Y-m-d H:i:s', $cutoff_ts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct query required for custom table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . esc_sql( $table_name ) . '` WHERE created_at < %s',
				$cutoff_date
			)
		);
	}
}
