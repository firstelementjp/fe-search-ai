<?php
class FEAS_AI_Installer {

	/**
	 * プラグイン有効化時に実行される処理
	 */
	public static function activate() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		// --- 1. ベクトル保存用テーブル ---
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

		// --- 2. ログ保存用テーブル ---
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

		// --- 3. キーワード索引テーブル ---
		$table_name_index = $wpdb->prefix . 'feas_ai_keyword_index';
		$sql_index = "CREATE TABLE `{$table_name_index}` (
			`keyword` varchar(100) NOT NULL,
			`vector_id` mediumint(9) NOT NULL,
			PRIMARY KEY (`keyword`, `vector_id`),
			KEY `keyword` (`keyword`),
			KEY `vector_id` (`vector_id`)
		) {$charset_collate};";
		dbDelta( $sql_index );

		// Cronジョブのスケジュールを追加
		if ( ! wp_next_scheduled( 'feas_ai_daily_log_rotation_event' ) ) {
			wp_schedule_event( time(), 'daily', 'feas_ai_daily_log_rotation_event' );
		}
	}

	/**
	 * プラグイン停止時に実行される処理
	 */
	public static function deactivate() {
		// Cronジョブのスケジュール解除を追加
		wp_clear_scheduled_hook( 'feas_ai_daily_log_rotation_event' );
	}
}
