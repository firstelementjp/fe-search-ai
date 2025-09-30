<?php
/**
 * Fired during plugin activation and deactivation.
 *
 * This class defines all code necessary to run during the plugin's
 * activation and deactivation hooks.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.0.0
 */

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The activator class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the list of all hooks that are registered throughout
 * the plugin, and registers them with the WordPress API. Call the
 * run function to execute the list of actions and filters.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Activator {

	public static function activate() {
		global $wpdb;
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$charset_collate = $wpdb->get_charset_collate();

		// Vector Storage Table
		$table_name_vectors = $wpdb->prefix . 'feas_ai_vectors';
		$sql_vectors = "CREATE TABLE `{$table_name_vectors}` (
			`id` mediumint(9) NOT NULL AUTO_INCREMENT,
			`post_id` bigint(20) UNSIGNED NOT NULL,
			`chunk_index` smallint(5) NOT NULL,
			`content_chunk` mediumtext NOT NULL,
			`vector_data` longtext NOT NULL,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `post_id` (`post_id`)
		) {$charset_collate};";
		dbDelta( $sql_vectors );

		// Table for Log Storage
		$table_name_logs = $wpdb->prefix . 'feas_ai_logs';
		$sql_logs = "CREATE TABLE `{$table_name_logs}` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`session_id` varchar(32) NOT NULL,
			`question` text NOT NULL,
			`answer` longtext,
			`sources` text,
			`context_found` tinyint(1) NOT NULL DEFAULT 0,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `created_at` (`created_at`)
		) {$charset_collate};";
		dbDelta( $sql_logs );

		// Keyword Index Table
		$table_name_index = $wpdb->prefix . 'feas_ai_keyword_index';
		$sql_index = "CREATE TABLE `{$table_name_index}` (
			`keyword` varchar(100) NOT NULL,
			`vector_id` mediumint(9) NOT NULL,
			PRIMARY KEY (`keyword`, `vector_id`),
			KEY `keyword` (`keyword`),
			KEY `vector_id` (`vector_id`)
		) {$charset_collate};";
		dbDelta( $sql_index );

		// Registering Cron Job
		if ( ! wp_next_scheduled( 'feas_ai_daily_log_rotation_event' ) ) {
			wp_schedule_event( time(), 'daily', 'feas_ai_daily_log_rotation_event' );
		}
	}

	public static function deactivate() {
		// Canceling Cron Job
		wp_clear_scheduled_hook( 'feas_ai_daily_log_rotation_event' );
	}
}
