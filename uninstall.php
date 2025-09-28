<?php
// WordPressのアンインストールプロセス以外で呼び出された場合は終了
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( get_option( 'feas_ai_delete_on_uninstall' ) ) {
	global $wpdb;

	// --- 1. カスタムテーブルを削除 ---
	$table_names = array(
		$wpdb->prefix . 'feas_ai_vectors',
		$wpdb->prefix . 'feas_ai_logs',
		$wpdb->prefix . 'feas_ai_keyword_index',
	);

	foreach ( $table_names as $table_name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	}

	// --- 2. オプションを削除 ---
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

	// --- 3. Cronジョブを削除 ---
	wp_clear_scheduled_hook( 'feas_ai_daily_log_rotation_event' );
}
