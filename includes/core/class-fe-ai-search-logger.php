<?php
/**
 * A dedicated helper class for system logging.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.2.0
 */

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class FE_AI_Search_Logger {

	private $options = [];

	public function __construct( $sync_handler ) {
		$this->options = get_option( 'fe_ai_search_settings', [] );
	}

	/**
	 * Records a system-level event to the database if debug mode is enabled.
	 *
	 * This is a static method so it can be called from anywhere in the plugin
	 * without needing to instantiate the class.
	 *
	 * @param string $level   The log level (e.g., 'INFO', 'SUCCESS', 'ERROR').
	 * @param string $message The main log message.
	 * @param array  $data    Optional. Additional data to store as JSON.
	 */
	public static function log( string $level, string $message, array $data = [] ) {
		$is_enabled = $this->options['data']['debug_options']['is_enabled'];
		if ( ! $is_enabled ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_ai_search_system_logs';

		// Check if the table exists to prevent errors.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
			return;
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
}
