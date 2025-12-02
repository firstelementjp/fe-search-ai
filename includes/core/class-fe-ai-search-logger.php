<?php
/**
 * A dedicated helper class for system logging.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System logger utility class for FE AI Search.
 *
 * Provides a static interface for writing structured log entries to the
 * custom system logs table when debug mode is enabled.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Core
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_Logger {

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
		$options    = get_option( 'fe_ai_search_settings', [] );
		$is_enabled = $options['advanced']['debug_mode'] ?? false;
		if ( ! $is_enabled ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_ai_search_system_logs';

		// Check if the table exists to prevent errors.
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
	 * Deletes all system log entries from the custom logs table.
	 *
	 * This method is intended to be called from an admin-only context when
	 * the site owner explicitly requests a full log purge.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_logs() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_ai_search_system_logs';

		// Check if the table exists before attempting to truncate.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// Use TRUNCATE to efficiently delete all rows.
		$wpdb->query( "TRUNCATE TABLE `{$table_name}`" );
	}
}
