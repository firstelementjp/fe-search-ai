<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * When a user deletes the plugin from the WordPress admin, this file is executed
 * to remove all of the plugin's data from the database, such as custom tables
 * and options.
 *
 * @package    fe-ai-search
 * @since      1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( get_option( 'fe_ai_search_delete_on_uninstall' ) ) {
	global $wpdb;

	// Delete custom tables
	$table_names = [
		$wpdb->prefix . 'fe_ai_search_vectors',
		$wpdb->prefix . 'fe_ai_search_logs',
		$wpdb->prefix . 'fe_ai_search_keyword_index',
		$wpdb->prefix . 'fe_ai_search_system_logs',
	];

	foreach ( $table_names as $table_name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	}

	// Delete options
	$option_names = [
		'fe_ai_search_settings',
		'fe_ai_search_license',
		'fe_ai_search_sync_state',
	];

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}
	delete_option( 'fe_ai_search_delete_on_uninstall' );

	// Delete Cron Job
	wp_clear_scheduled_hook( 'fe_ai_search_daily_log_rotation_event' );
}
