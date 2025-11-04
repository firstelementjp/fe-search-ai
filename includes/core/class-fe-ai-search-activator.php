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
class FE_AI_Search_Activator {

	/**
	 * The current database version.
	 *
	 * This constant is used to track the version of the database schema.
	 * It should be incremented whenever the schema in create_tables() is changed.
	 *
	 * @since 1.0.0
	 */
	const DB_VERSION = '1.0';

	/**
	 * Fired when the plugin is activated.
	 *
	 * Creates the custom tables and sets the initial database version.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		self::create_tables();
		self::schedule_cron_jobs();

		$options = get_option( 'fe_ai_search_settings', [] );
		$options['advanced']['db_version'] = self::DB_VERSION;
		update_option( 'fe_ai_search_settings', $options );
	}

	/**
	 * Fired when the plugin is deactivated.
	 *
	 * Clears any scheduled cron jobs.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Canceling Cron Job
		wp_clear_scheduled_hook( 'fe_ai_search_daily_log_rotation_event' );
	}

	/**
	 * Checks the database version and runs schema updates if needed.
	 *
	 * This method compares the saved DB version with the current DB_VERSION constant
	 * and triggers the create_tables() method if an update is required.
	 * It is hooked to 'plugins_loaded' to run on every page load.
	 *
	 * @since 1.0.0
	 */
	public static function check_db_version() {
		$options           = get_option( 'fe_ai_search_settings', [] );
		$installed_version = $options['advanced']['db_version'] ?? '1.0';

		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			// Version is old, call the static method create_tables
			self::create_tables();

			// Update the DB version in the master options array
			$options['advanced']['db_version'] = self::DB_VERSION;
			update_option( 'fe_ai_search_settings', $options );
		}
	}

	/**
	 * Creates or updates the custom database tables using dbDelta.
	 *
	 * @since 1.0.0
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$charset_collate = $wpdb->get_charset_collate();

		// Vector Storage Table
		$table_name_vectors = $wpdb->prefix . 'fe_ai_search_vectors';
		$sql_vectors = "CREATE TABLE `{$table_name_vectors}` (
			`id` mediumint(9) NOT NULL AUTO_INCREMENT,
			`post_id` bigint(20) UNSIGNED NOT NULL,
			`lang` varchar(10) NOT NULL DEFAULT '',
			`chunk_index` smallint(5) NOT NULL,
			`content_chunk` mediumtext NOT NULL,
			`vector_data` longtext NOT NULL,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `post_id` (`post_id`)
		) {$charset_collate};";
		dbDelta( $sql_vectors );

		// Keyword Index Table
		$table_name_index = $wpdb->prefix . 'fe_ai_search_keyword_index';
		$sql_index = "CREATE TABLE `{$table_name_index}` (
			`keyword` varchar(100) NOT NULL,
			`vector_id` mediumint(9) NOT NULL,
			`lang` varchar(10) NOT NULL DEFAULT '',
			PRIMARY KEY (`keyword`, `vector_id`),
			KEY `keyword` (`keyword`),
			KEY `vector_id` (`vector_id`)
		) {$charset_collate};";
		dbDelta( $sql_index );

		// System Logs Table
		$table_name = $wpdb->prefix . 'fe_ai_search_system_logs';
		$sql = "CREATE TABLE $table_name (
			`id` bigint(20) NOT NULL AUTO_INCREMENT,
			`level` varchar(20) NOT NULL DEFAULT 'INFO',
			`message` text NOT NULL,
			`extra_data` longtext,
			`created_at` datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (`id`),
			KEY `level` (`level`)
		) $charset_collate;";
		dbDelta( $sql );
	}

	/**
	 * Schedules the daily cron job for log rotation.
	 *
	 * @since 1.0.0
	 */
	public static function schedule_cron_jobs() {
		// Registering Cron Job
		if ( ! wp_next_scheduled( 'fe_ai_search_daily_log_rotation_event' ) ) {
			wp_schedule_event( time(), 'daily', 'fe_ai_search_daily_log_rotation_event' );
		}
	}
}
