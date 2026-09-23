<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * When a user deletes the plugin from the WordPress admin, this file is executed
 * to remove all of the plugin's data from the database, such as custom tables
 * and options. Deletion is controlled by the `advanced.delete_on_uninstall`
 * setting inside the `fe_search_ai_settings` option (the legacy standalone
 * `fe_search_ai_delete_on_uninstall` option is kept as a fallback).
 *
 * @package    fe-search-ai
 * @since      0.9.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$fe_search_ai_settings = get_option( 'fe_search_ai_settings', [] );
$fe_search_ai_delete   = ( is_array( $fe_search_ai_settings ) && ! empty( $fe_search_ai_settings['advanced']['delete_on_uninstall'] ) )
	|| get_option( 'fe_search_ai_delete_on_uninstall' );

if ( $fe_search_ai_delete ) {
	global $wpdb;

	// Delete custom tables.
	// Local variable, not global.
	$table_names = [
		$wpdb->prefix . 'fe_search_ai_vectors',
		$wpdb->prefix . 'fe_search_ai_keyword_index',
		$wpdb->prefix . 'fe_search_ai_system_logs',
		$wpdb->prefix . 'fe_search_ai_logs',
		$wpdb->prefix . 'fe_search_ai_retrieval_traces',
		$wpdb->prefix . 'fe_search_ai_retrieval_trace_items',
	];

	foreach ( $table_names as $table_name ) {
		// Local variable, not global.
		// Table name is interpolated but controlled internally.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	}

	// Delete options.
	// Local variable, not global.
	$option_names = [
		'fe_search_ai_settings',
		'fe_search_ai_custom_prompts',
		'fe_search_ai_site_info',
		'fe_search_ai_sync_state',
		'fe_search_ai_license',
		'fe_search_ai_chat_provider',
		'fe_search_ai_embedding_provider',
		'fe_search_ai_openai_api_key',
		'fe_search_ai_google_api_key',
		'fe_search_ai_anthropic_api_key',
		'fe_search_ai_log_retention_days',
		'fe_search_ai_sync_post_types',
		'fe_search_ai_include_post_ids',
		'fe_search_ai_exclude_post_ids',
		'fe_search_ai_sync_limit',
		'fe_search_ai_delete_on_uninstall',
	];

	foreach ( $option_names as $option_name ) {
		// Local variable, not global.
		delete_option( $option_name );
	}

	// Delete transients.
	// Local variable, not global.
	$transient_names = [
		'fe_search_ai_license_error',
		'fe_search_ai_rl_notify_sent',
		'fe_search_ai_rl_global_day',
		'fe_search_ai_i18n_notice_dismissed',
		'fe_search_ai_consent_links_missing',
	];

	foreach ( $transient_names as $transient_name ) {
		// Local variable, not global.
		delete_transient( $transient_name );
	}

	// The GitHub release cache is stored as a site transient.
	delete_site_transient( 'fe_search_ai_github_latest_release' );

	// Delete rate-limit transients keyed by hashed IP address.
	foreach ( [ '_transient_fe_search_ai_rl_ip_', '_transient_timeout_fe_search_ai_rl_ip_' ] as $fe_search_ai_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $fe_search_ai_prefix ) . '%'
			)
		);
	}

	// Delete Cron Job.
	wp_clear_scheduled_hook( 'fe_search_ai_daily_log_rotation_event' );
}
