<?php
/**
 * Fired during plugin activation and deactivation.
 *
 * This class defines all code necessary to run during the plugin's
 * activation and deactivation hooks.
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
 * The activator class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the list of all hooks that are registered throughout
 * the plugin, and registers them with the WordPress API. Call the
 * run function to execute the list of actions and filters.
 *
 * @since 0.9.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Activator {

	/**
	 * The current database version.
	 *
	 * This constant is used to track the version of the database schema.
	 * It should be incremented whenever the schema in create_tables() is changed.
	 *
	 * @since 0.9.0
	 */
	const DB_VERSION = '1.2';

	/**
	 * Fired when the plugin is activated.
	 *
	 * Creates the custom tables, sets the initial database version,
	 * and sets default settings values.
	 *
	 * @since 0.9.0
	 */
	public static function activate() {
		self::create_tables();
		self::schedule_cron_jobs();
		self::set_default_settings();

		$options                           = get_option( 'fe_search_ai_settings', [] );
		$options['advanced']['db_version'] = self::DB_VERSION;
		update_option( 'fe_search_ai_settings', $options );
	}

	/**
	 * Fired when the plugin is deactivated.
	 *
	 * Clears any scheduled cron jobs.
	 *
	 * @since 0.9.0
	 */
	public static function deactivate() {
		// Canceling Cron Job.
		wp_clear_scheduled_hook( 'fe_search_ai_daily_log_rotation_event' );
	}

	/**
	 * Checks the database version and runs schema updates if needed.
	 *
	 * This method compares the saved DB version with the current DB_VERSION constant
	 * and triggers the create_tables() method if an update is required.
	 * It is hooked to 'plugins_loaded' to run on every page load.
	 *
	 * @since 0.9.0
	 */
	public static function check_db_version() {
		$options           = get_option( 'fe_search_ai_settings', [] );
		$installed_version = $options['advanced']['db_version'] ?? '1.0';

		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			// Version is old, call the static method create_tables.
			self::create_tables();

			// Update the DB version in the master options array.
			$options['advanced']['db_version'] = self::DB_VERSION;
			update_option( 'fe_search_ai_settings', $options );
		}
	}

	/**
	 * Creates or updates the custom database tables using dbDelta.
	 *
	 * @since 0.9.0
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Vector Storage Table.
		$table_name_vectors = $wpdb->prefix . 'fe_search_ai_vectors';
		$sql_vectors        = "CREATE TABLE `{$table_name_vectors}` (
			`id` mediumint(9) NOT NULL AUTO_INCREMENT,
			`post_id` bigint(20) UNSIGNED NOT NULL,
			`lang` varchar(10) NOT NULL DEFAULT '',
			`chunk_index` smallint(5) NOT NULL,
			`content_chunk` mediumtext NOT NULL,
			`summary_text` mediumtext,
			`summary_hash` varchar(32) NOT NULL DEFAULT '',
			`vector_data` longtext NOT NULL,
			`embedding_model` varchar(100) NOT NULL DEFAULT '',
			`embedding_dim` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `post_id` (`post_id`)
		) {$charset_collate};";
		dbDelta( $sql_vectors );

		// Keyword Index Table.
		$table_name_index = $wpdb->prefix . 'fe_search_ai_keyword_index';
		$sql_index        = "CREATE TABLE `{$table_name_index}` (
			`keyword` varchar(100) NOT NULL,
			`vector_id` mediumint(9) NOT NULL,
			`lang` varchar(10) NOT NULL DEFAULT '',
			PRIMARY KEY (`keyword`, `vector_id`),
			KEY `keyword` (`keyword`),
			KEY `vector_id` (`vector_id`)
		) {$charset_collate};";
		dbDelta( $sql_index );

		// System Logs Table.
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$sql        = "CREATE TABLE $table_name (
			`id` bigint(20) NOT NULL AUTO_INCREMENT,
			`level` varchar(20) NOT NULL DEFAULT 'INFO',
			`message` text NOT NULL,
			`extra_data` longtext,
			`created_at` datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			`sequence_id` varchar(64) DEFAULT '',
			`sequence_order` int(11) DEFAULT 0,
			PRIMARY KEY  (`id`),
			KEY `level` (`level`),
			KEY `sequence` (`sequence_id`, `sequence_order`)
		) $charset_collate;";
		dbDelta( $sql );
	}

	/**
	 * Schedules the daily cron job for log rotation.
	 *
	 * @since 0.9.0
	 */
	public static function schedule_cron_jobs() {
		// Registering Cron Job.
		if ( ! wp_next_scheduled( 'fe_search_ai_daily_log_rotation_event' ) ) {
			wp_schedule_event( time(), 'daily', 'fe_search_ai_daily_log_rotation_event' );
		}
	}

	/**
	 * Sets default settings values on plugin activation.
	 *
	 * This ensures that the floating chat is enabled by default for better UX.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function set_default_settings() {
		$options = get_option( 'fe_search_ai_settings', [] );

		// Set default floating chat settings if not already set.
		if ( ! isset( $options['display']['floating'] ) ) {
			$options['display']['floating'] = [
				'enable_floating_mode'    => true,
				'display_on_pc'           => true,
				'display_on_mobile'       => true,
				'show_to_logged_in_users' => true,
				'show_to_guests'          => true,
				'display_rules'           => [
					'show_on_front_page' => true,
					'show_on_archives'   => true,
					'show_on_search'     => true,
					'show_on_404'        => true,
					'show_on_singular'   => true,
					'include_ids'        => '',
					'exclude_ids'        => '',
				],
			];
		}

		// Set default provider settings if not already set.
		if ( ! isset( $options['provider'] ) ) {
			$options['provider'] = [
				'embedding' => 'openai',
				'chat'      => 'openai',
				'rerank'    => 'cohere',
			];
		}

		update_option( 'fe_search_ai_settings', $options );
	}
}
