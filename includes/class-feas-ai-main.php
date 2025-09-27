<?php

use U7aro\TinySegmenter\TinySegmenter;

class FEAS_AI_Main {

	public $ajax;
	public $assets;

	public function __construct() {
		// 各機能クラスを初期化
		// FEAS_AI_AjaxとFEAS_AI_Assetsはメインローダーで読み込まれている前提
		$this->ajax = new FEAS_AI_Ajax();
		$this->assets = new FEAS_AI_Assets();

		// アクションフックを登録
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'wp_footer', array( $this, 'add_chat_ui_html' ) );
		add_action( 'feas_ai_daily_log_rotation_event', array( $this, 'execute_log_rotation' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function add_admin_menu() {

		$parent_slug = 'fe-ai-search';

		add_menu_page(
			'FE AI Search',                 // ページタイトル
			'FE AI Search',                 // メニューに表示される名前
			'manage_options',               // 権限
			$parent_slug,                   // メニュースラッグ
			array( $this, 'settings_page_html' ), // このメニューがクリックされた時に表示するページ
			'dashicons-search',             // アイコン ( https://developer.wordpress.org/resource/dashicons/ )
			80                              // 表示位置（数字が大きいほど下）
		);

		// 2. 「設定」サブメニューを追加
		add_submenu_page(
			$parent_slug,                   // 親のスラッグ
			'Settings',                     // ページタイトル
			'Settings',                     // メニュータイトル
			'manage_options',               // 権限
			$parent_slug,                   // 親と同じスラッグにすることで、親をクリックした時のページになる
			array( $this, 'settings_page_html' )
		);

		// 3. 「アナリティクス」サブメニューを追加
		add_submenu_page(
			$parent_slug,
			'Search Analytics',
			'Analytics',
			'manage_options',
			$parent_slug . '-analytics',     // 固有のスラッグ
			array( $this, 'analytics_page_html' )
		);
	}

	public function settings_init() {
		$settings_group = 'feas-ai-settings';
		$page_slug      = 'fe-ai-search';

		register_setting( $settings_group, 'feas_ai_openai_api_key' );
		register_setting( $settings_group, 'feas_ai_api_provider' );
		register_setting( $settings_group, 'feas_ai_google_api_key' );
		register_setting( $settings_group, 'feas_ai_log_retention_days' );

		add_settings_section(
			'feas_ai_general_section',
			'API設定',
			array( $this, 'general_section_callback' ),
			$page_slug
		);

		add_settings_field(
			'feas_ai_api_provider',
			'API Provider',
			array( $this, 'api_provider_field_html' ),
			$page_slug,
			'feas_ai_general_section'
		);

		add_settings_field(
			'feas_ai_openai_api_key',
			'OpenAI API Key',
			array( $this, 'openai_api_key_field_html' ),
			$page_slug,
			'feas_ai_general_section'
		);

		add_settings_field(
			'feas_ai_google_api_key',
			'Google Cloud API Key',
			array( $this, 'google_api_key_field_html' ),
			$page_slug,
			'feas_ai_general_section'
		);

		add_settings_field(
			'feas_ai_log_retention_days',
			'ログ保存期間（日数）',
			array( $this, 'log_retention_field_html' ),
			$page_slug,
			'feas_ai_general_section'
		);
	}

	public function settings_page_html() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'feas-ai-settings' );
				do_settings_sections( 'fe-ai-search' );
				submit_button( '設定を保存' );
				?>
			</form>
			<hr>
			<h2>コンテンツの同期</h2>
			<p>サイト内の投稿や固定ページをAIが検索できるように、データを準備・索引化します。</p>
			<div id="feas-ai-sync-wrapper">
				<button id="feas-ai-start-sync" class="button button-primary">同期を開始する</button>

				<div id="feas-ai-progress-container" style="display: none; margin-top: 10px;">
					<div id="feas-ai-progress-bar-wrapper" style="width: 100%; background-color: #ddd; border: 1px solid #ccc;">
						<div id="feas-ai-progress-bar" style="width: 0%; height: 24px; background-color: #0073aa; text-align: right; color: white; line-height: 24px; padding-right: 8px; box-sizing: border-box;">0%</div>
					</div>
					<div id="feas-ai-sync-status" style="margin-top: 5px; min-height: 20px;">
						<span class="dashicons dashicons-update-alt spin" style="display: none; vertical-align: middle; margin-right: 5px;"></span>
						<span class="status-text"></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function general_section_callback() {
		echo '<p>AI検索機能を利用するためのAPI設定を行います。</p>';
	}

	public function api_provider_field_html() {
		$provider = get_option( 'feas_ai_api_provider', 'openai' );
		?>
		<fieldset>
			<label><input type="radio" name="feas_ai_api_provider" value="openai" <?php checked( 'openai', $provider ); ?>> <span>OpenAI</span></label><br>
			<label><input type="radio" name="feas_ai_api_provider" value="google" <?php checked( 'google', $provider ); ?>> <span>Google (Gemini)</span></label>
		</fieldset>
		<?php
	}

	public function openai_api_key_field_html() {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		?>
		<input type="password" name="feas_ai_openai_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
		<p class="description">OpenAIのAPIキーを入力してください。</p>
		<?php
	}

	public function google_api_key_field_html() {
		$api_key = get_option( 'feas_ai_google_api_key' );
		?>
		<input type="password" name="feas_ai_google_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
		<p class="description">Google CloudのAPIキーを入力してください。</p>
		<?php
	}

	public function add_chat_ui_html() {
		?>
		<div id="feas-ai-chat-container">
			<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
			<div id="feas-ai-chat-bubble">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path></svg>
			</div>
			<div id="feas-ai-chat-window" class="hidden">
				<div id="feas-ai-chat-header">
					<h3>サイト内をAI検索</h3>
					<button id="feas-ai-chat-close">&times;</button>
				</div>
				<div id="feas-ai-chat-messages">
					<div class="feas-ai-message feas-ai-message-ai">
						<p>こんにちは！サイト内の情報について、何でも質問してください。</p>
					</div>
				</div>
				<div id="feas-ai-chat-footer">
					<form id="feas-ai-chat-form">
						<input type="text" id="feas-ai-chat-input" placeholder="質問を入力してください..." autocomplete="off">
						<button type="submit">送信</button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	public function analytics_page_html() {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'feas_ai_logs';

		if ( isset( $_POST['feas_ai_delete_logs'] ) && check_admin_referer( 'feas_ai_delete_logs_nonce' ) ) {
			$wpdb->query( "TRUNCATE TABLE `{$logs_table}`" );
			echo '<div class="notice notice-success is-dismissible"><p>すべての検索ログを削除しました。</p></div>';
		}

		$logs = $wpdb->get_results( "SELECT * FROM `{$logs_table}` ORDER BY `created_at` DESC LIMIT 100" );
		?>
		<div class="wrap">
			<h1>Search Analytics</h1>
			<p>ユーザーからの直近100件の質問履歴です。</p>

			<form method="post" action="" style="margin-bottom: 20px;">
				<?php wp_nonce_field( 'feas_ai_delete_logs_nonce' ); ?>
				<p class="description">注意：この操作は元に戻せません。</p>
				<button type="submit" name="feas_ai_delete_logs" class="button button-danger" onclick="return confirm('本当にすべてのログを削除しますか？');">
					全ログを削除
				</button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 15%;">検索日時</th>
						<th style="width: 15%;">セッションID</th>
						<th style="width: 30%;">質問内容</th>
						<th style="width: 30%;">AIの回答</th>
						<th style="width: 10%;">関連情報</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="5">まだログがありません。</td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->created_at ); ?></td>
								<td><?php echo esc_html( $log->session_id ); ?></td>
								<td><?php echo esc_html( $log->question ); ?></td>
								<td>
									<div style="max-height: 100px; overflow-y: auto;">
										<?php echo wp_kses_post( $log->answer ); ?>
									</div>
								</td>
								<td>
									<?php if ( $log->context_found ) : ?>
										<span style="color: green;">✔</span>
									<?php else : ?>
										<span style="color: red;">✖</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function log_retention_field_html() {
		$days = get_option( 'feas_ai_log_retention_days', 30 ); // デフォルト30日
		?>
		<input type="number" name="feas_ai_log_retention_days" value="<?php echo esc_attr( $days ); ?>" class="small-text"> 日
		<p class="description">これ以上古くなった検索ログは、毎日自動的に削除されます。0にすると削除しません。</p>
		<?php
	}

	/**
	 * 古いログを削除するCronジョブ
	 */
	public function execute_log_rotation() {
		$days_to_keep = (int) get_option( 'feas_ai_log_retention_days', 30 );

		if ( $days_to_keep <= 0 ) {
			return; // 0日以下の場合は何もしない
		}

		global $wpdb;
		$logs_table = $wpdb->prefix . 'feas_ai_logs';

		$threshold_date = date( 'Y-m-d H:i:s', strtotime( "-{$days_to_keep} days" ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$logs_table}` WHERE `created_at` < %s",
				$threshold_date
			)
		);
	}

	/**
	 * 管理画面用のスクリプトとスタイルを読み込む
	 */
	public function enqueue_admin_assets( $hook_suffix ) {

		$allowed_hooks = array(
			'toplevel_page_fe-ai-search',             // 親メニューのページ
			'fe-ai-search_page_fe-ai-search-analytics'  // Analyticsサブメニューのページ
		);

		if ( ! in_array( $hook_suffix, $allowed_hooks ) ) {
			return;
		}

		// 自分の設定ページでのみスクリプトを読み込む
		// if ( 'settings_page_fe-ai-search' !== $hook_suffix && 'fe-ai-search_page_fe-ai-search-analytics' !== $hook_suffix ) {
		// 	return;
		// }

		wp_enqueue_style(
			'feas-ai-admin-style',
			plugin_dir_url( __DIR__ ) . 'assets/css/admin-style.css',
			array(),
			FEAS_AI_VERSION
		);

		wp_enqueue_script(
			'feas-ai-admin-sync',
			plugin_dir_url( __DIR__ ) . 'assets/js/admin-sync.js',
			array('jquery'), // jQueryに依存
			FEAS_AI_VERSION,
			true
		);

		// ★ 同期JSが必要とするNonceをここで渡す
		wp_localize_script(
			'feas-ai-admin-sync',
			'feas_ai_sync_obj',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'feas_ai_ajax_nonce' ),
			)
		);
	}
}
