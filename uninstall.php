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

if ( get_option( 'feas_ai_delete_on_uninstall' ) ) {
	global $wpdb;

	// Delete custom table
	$table_names = array(
		$wpdb->prefix . 'feas_ai_vectors',
		$wpdb->prefix . 'feas_ai_logs',
		$wpdb->prefix . 'feas_ai_keyword_index',
	);

	foreach ( $table_names as $table_name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	}

	// Delete option
	$option_names = array(
		'feas_ai_chat_provider',
		'feas_ai_embedding_provider',
		'feas_ai_openai_api_key',
		'feas_ai_google_api_key',
		'feas_ai_anthropic_api_key',
		'feas_ai_log_retention_days',
		'feas_ai_sync_post_types',
		'feas_ai_include_post_ids',
		'feas_ai_exclude_post_ids',
		'feas_ai_sync_limit',
	);

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}
	delete_option( 'feas_ai_delete_on_uninstall' );

	// Delete Cron Job
	wp_clear_scheduled_hook( 'feas_ai_daily_log_rotation_event' );
}
