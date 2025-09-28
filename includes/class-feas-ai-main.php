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
		// add_action( 'wp_footer', array( $this, 'add_chat_ui_html' ) );
		add_action( 'feas_ai_daily_log_rotation_event', array( $this, 'execute_log_rotation' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// 投稿が公開・更新された時に実行
		add_action( 'save_post', array( $this, 'sync_single_post_on_update' ), 10, 2 );
		// 投稿がゴミ箱に入れられた時に実行
		add_action( 'wp_trash_post', array( $this, 'delete_post_from_index' ) );
		// ゴミ箱から完全に削除された時に実行
		add_action( 'delete_post', array( $this, 'delete_post_from_index' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_footer', array( $this, 'maybe_render_floating_chat' ) );
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

		register_setting( $settings_group, 'feas_ai_chat_provider' );      // ★ 回答生成用
		register_setting( $settings_group, 'feas_ai_embedding_provider' ); // ★ ベクトル化用

		// register_setting( $settings_group, 'feas_ai_api_provider' );
		register_setting( $settings_group, 'feas_ai_openai_api_key' );
		register_setting( $settings_group, 'feas_ai_google_api_key' );
		register_setting( $settings_group, 'feas_ai_anthropic_api_key' );
		register_setting( $settings_group, 'feas_ai_log_retention_days' );
		register_setting( $settings_group, 'feas_ai_sync_post_types' );
		register_setting( $settings_group, 'feas_ai_include_post_ids' );
		register_setting( $settings_group, 'feas_ai_exclude_post_ids' );
		register_setting( $settings_group, 'feas_ai_sync_limit' );
		register_setting( $settings_group, 'feas_ai_delete_on_uninstall' );
		register_setting( $settings_group, 'feas_ai_display_mode' );

		add_settings_section(
			'feas_ai_general_section',
			'API設定',
			array( $this, 'general_section_callback' ),
			$page_slug
		);
		add_settings_section(
			'feas_ai_sync_section', // 同期設定用の新しいセクション
			'同期設定',
			null, // コールバックは不要
			$page_slug
		);
		add_settings_section(
			'feas_ai_display_section', // 表示設定用の新しいセクション
			'表示設定',
			null,
			$page_slug
		);

		add_settings_field(
			'feas_ai_chat_provider',
			'回答生成モデル',
			array( $this, 'chat_provider_field_html' ),
			$page_slug,
			'feas_ai_general_section'
		);

		add_settings_field(
			'feas_ai_embedding_provider',
			'ベクトル化モデル',
			array( $this, 'embedding_provider_field_html' ),
			$page_slug,
			'feas_ai_general_section'
		);

		add_settings_field( 'feas_ai_openai_api_key', 'OpenAI API Key', array( $this, 'openai_api_key_field_html' ), $page_slug, 'feas_ai_general_section' );
		add_settings_field( 'feas_ai_google_api_key', 'Google Cloud API Key', array( $this, 'google_api_key_field_html' ), $page_slug, 'feas_ai_general_section' );
		add_settings_field( 'feas_ai_anthropic_api_key', 'Anthropic (Claude) API Key', array( $this, 'anthropic_api_key_field_html' ), $page_slug, 'feas_ai_general_section' );
		add_settings_field( 'feas_ai_log_retention_days', 'ログ保存期間（日数）', array( $this, 'log_retention_field_html' ), $page_slug, 'feas_ai_general_section' );
		add_settings_field(
			'feas_ai_sync_post_types',
			'同期対象の投稿タイプ',
			array( $this, 'sync_post_types_field_html' ),
			$page_slug,
			'feas_ai_sync_section' // 新しいセクションに所属させる
		);
		add_settings_field(
			'feas_ai_sync_limit',
			'同期する最大投稿数',
			array( $this, 'sync_limit_field_html' ),
			$page_slug,
			'feas_ai_sync_section'
		);
		add_settings_field(
			'feas_ai_delete_on_uninstall',
			'アンインストール時の設定',
			array( $this, 'delete_on_uninstall_field_html' ),
			$page_slug,
			'feas_ai_sync_section' // 同期設定セクションに追加
		);
		add_settings_field(
			'feas_ai_include_post_ids',
			'含める投稿ID',
			array( $this, 'include_post_ids_field_html' ),
			$page_slug,
			'feas_ai_sync_section'
		);
		add_settings_field(
			'feas_ai_exclude_post_ids',
			'除外する投稿ID',
			array( $this, 'exclude_post_ids_field_html' ),
			$page_slug,
			'feas_ai_sync_section'
		);
		add_settings_field(
			'feas_ai_display_mode',
			'表示モード',
			array( $this, 'display_mode_field_html' ),
			$page_slug,
			'feas_ai_display_section' // 新しいセクションに所属
		);

	}

	public function settings_page_html() {
		// フォーム送信の処理を関数の先頭にまとめる
		if ( isset( $_POST['feas_ai_delete_vectors'] ) && check_admin_referer( 'feas_ai_delete_vectors_nonce' ) ) {
			global $wpdb;
			$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
			$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';
			$wpdb->query( "TRUNCATE TABLE `{$vectors_table}`" );
			$wpdb->query( "TRUNCATE TABLE `{$index_table}`" );
			delete_option( 'feas_ai_last_sync_timestamp' );
			echo '<div class="notice notice-success is-dismissible"><p>すべての同期データ（ベクトル、キーワード索引）を削除しました。</p></div>';
		}
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

			<?php
			// --- 最終同期日時と登録記事数を表示 ---
			global $wpdb;
			$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
			$indexed_post_count = $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM `{$vectors_table}`" );
			$last_sync = get_option( 'feas_ai_last_sync_timestamp' );

			echo '<div style="margin-bottom: 1em; padding: 10px; background-color: #f7f7f7; border: 1px solid #ddd;">';
			if ( $last_sync ) {
				echo '<p style="margin: 0;"><strong>最終同期日時:</strong> ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) . '</p>';
			}
			echo '<p style="margin: 0;"><strong>インデックス登録済み記事数:</strong> ' . esc_html( $indexed_post_count ) . ' 件</p>';
			echo '</div>';
			?>

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

			<div style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px;">
				<h3>データ管理</h3>
				<form method="post" action="">
					<p>同期されたベクトルデータとキーワード索引をすべて削除します。再度同期を行うまで、AI検索は機能しなくなります。</p>
					<?php wp_nonce_field( 'feas_ai_delete_vectors_nonce' ); ?>
					<button type="submit" name="feas_ai_delete_vectors" class="button button-secondary" onclick="return confirm('本当にすべての同期データを削除しますか？この操作は元に戻せません。');">
						同期データを全削除
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	public function general_section_callback() {
		echo '<p>AI検索機能を利用するためのAPI設定を行います。</p>';
	}

	public function chat_provider_field_html() {
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );
		?>
		<select name="feas_ai_chat_provider">
			<option value="openai" <?php selected( $provider, 'openai' ); ?>>OpenAI (GPT-4oなど)</option>
			<option value="google" <?php selected( $provider, 'google' ); ?>>Google (Gemini)</option>
			<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>>Anthropic (Claude)</option>
		</select>
		<p class="description">ユーザーへの回答を生成するAIを選択します。</p>
		<?php
	}

	public function embedding_provider_field_html() {
		$provider = get_option( 'feas_ai_embedding_provider', 'openai' );
		?>
		<select name="feas_ai_embedding_provider">
			<option value="openai" <?php selected( $provider, 'openai' ); ?>>OpenAI (text-embedding-3)</option>
			<option value="google" <?php selected( $provider, 'google' ); ?>>Google (text-embedding-004)</option>
			<option value="anthropic" disabled="disabled">Anthropic (Claude) - (現在API未提供)</option>
		</select>
		<p class="description">サイト内コンテンツをベクトル化（AIが理解できる形式に変換）するAIを選択します。</p>
		<?php
	}

	public function openai_api_key_field_html() {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		?>
		<input type="password" id="feas_ai_openai_api_key" name="feas_ai_openai_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
		<button type="button" class="button button-secondary feas-ai-test-api" data-provider="openai">接続テスト</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<span class="feas-ai-api-status"></span>
		<p class="description">OpenAIのAPIキーを入力してください。</p>
		<?php
	}

	public function google_api_key_field_html() {
		$api_key = get_option( 'feas_ai_google_api_key' );
		?>
		<input type="password" id="feas_ai_google_api_key" name="feas_ai_google_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
		<button type="button" class="button button-secondary feas-ai-test-api" data-provider="google">接続テスト</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<span class="feas-ai-api-status"></span>
		<p class="description">Google CloudのAPIキーを入力してください。</p>
		<?php
	}

	public function anthropic_api_key_field_html() {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
		?>
		<input type="password" id="feas_ai_anthropic_api_key" name="feas_ai_anthropic_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
		<button type="button" class="button button-secondary feas-ai-test-api" data-provider="anthropic">接続テスト</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<span class="feas-ai-api-status"></span>
		<p class="description">Anthropic (Claude) のAPIキーを入力してください。</p>
		<?php
	}

	public function get_chat_ui_html( $mode = 'float' ) {
		ob_start(); // 出力バッファリングを開始
		?>
		<div id="feas-ai-chat-container" class="feas-ai-mode-<?php echo esc_attr( $mode ); ?>">
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
		return ob_get_clean(); // バッファの内容を取得して文字列として返し、バッファを閉じる
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

	public function sync_post_types_field_html() {
		// サイトに登録されている、検索に適した投稿タイプを取得
		$post_types = get_post_types( array('public' => true), 'objects' );

		// 不要な投稿タイプを除外
		$excluded_post_types = array('attachment');

		// DBに保存されている設定値を取得（デフォルトは投稿と固定ページ）
		$saved_post_types = get_option( 'feas_ai_sync_post_types', array('post', 'page') );
		if ( ! is_array($saved_post_types) ) {
			$saved_post_types = array('post', 'page');
		}

		echo '<fieldset>';
		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $excluded_post_types ) ) {
				continue;
			}
			?>
			<label>
				<input
					type="checkbox"
					name="feas_ai_sync_post_types[]"
					value="<?php echo esc_attr( $post_type->name ); ?>"
					<?php checked( in_array( $post_type->name, $saved_post_types ) ); ?>
				/>
				<?php echo esc_html( $post_type->label ); ?> (<code><?php echo esc_html( $post_type->name ); ?></code>)
			</label><br>
			<?php
		}
		echo '</fieldset>';
		echo '<p class="description">AI検索の対象とする投稿タイプを選択してください。</p>';
	}

	public function include_post_ids_field_html() {
		$ids = get_option( 'feas_ai_include_post_ids', '' );
		?>
		<input type="text" name="feas_ai_include_post_ids" value="<?php echo esc_attr( $ids ); ?>" class="regular-text">
		<p class="description">特定の投稿のみを同期対象にしたい場合、投稿IDをカンマ区切りで入力します。（例: 10, 25, 103）<br><strong>注意：</strong>ここに入力すると、「同期対象の投稿タイプ」の設定は無視され、これらのIDの投稿のみが同期されます。</p>
		<?php
	}

	public function exclude_post_ids_field_html() {
		$ids = get_option( 'feas_ai_exclude_post_ids', '' );
		?>
		<input type="text" name="feas_ai_exclude_post_ids" value="<?php echo esc_attr( $ids ); ?>" class="regular-text">
		<p class="description">同期対象から除外したい投稿がある場合、投稿IDをカンマ区切りで入力します。（例: 15, 30）</p>
		<?php
	}

	public function sync_limit_field_html() {
		$limit = get_option( 'feas_ai_sync_limit', 0 ); // デフォルトは0（全件）
		?>
		<input type="number" name="feas_ai_sync_limit" value="<?php echo esc_attr( $limit ); ?>" class="small-text"> 件
		<p class="description">同期する投稿を、最新のものから指定した件数に制限します。お試しでの同期や、APIコストを抑えたい場合に設定してください。<br><strong>0</strong>にすると、対象の投稿をすべて同期します。</p>
		<?php
	}

	// in includes/class-feas-ai-main.php (クラスの末尾)

	/**
	 * 投稿が更新された際に、単一の投稿を同期する
	 * @param int $post_id 投稿ID.
	 * @param WP_Post $post 投稿オブジェクト.
	 */
	public function sync_single_post_on_update( $post_id, $post ) {
		// 同期対象として設定した投稿タイプかチェック
		$post_types_to_sync = get_option( 'feas_ai_sync_post_types', array('post', 'page') );
		if ( ! in_array( $post->post_type, $post_types_to_sync ) ) {
			return;
		}
		// 公開されている投稿のみを対象
		if ( $post->post_status !== 'publish' ) {
			return;
		}
		// 自動保存やリビジョンは無視
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// まず、この投稿の古い索引とベクトルを削除
		$this->delete_post_from_index( $post_id );

		// ★★★ ajax_process_batch とほぼ同じロジックで、単一投稿を索引化 ★★★
		$segmenter = new TinySegmenter();
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );
		$raw_chunks = explode( "\n\n", $content );
		$valid_chunks = array_values( array_filter( array_map( 'trim', $raw_chunks ) ) );

		if ( empty( $valid_chunks ) ) { return; }

		$embedding_response = $this->ajax->get_embeddings_via_selected_provider( $valid_chunks );

		if ( ! is_wp_error( $embedding_response ) ) {
			$vectors_data = $embedding_response['data'];
			foreach ( $vectors_data as $index => $vector_item ) {
				$wpdb->insert(
					$vectors_table,
					array(
						'post_id'       => $post->ID,
						'chunk_index'   => $index,
						'content_chunk' => $valid_chunks[$index],
						'vector_data'   => json_encode( $vector_item['embedding'] ),
						'created_at'    => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s', '%s' )
				);
				$vector_id = $wpdb->insert_id;
				if ($vector_id) {
					$keywords = $segmenter->segment($valid_chunks[$index]);
					foreach ( array_unique( $keywords ) as $keyword ) {
						if ( mb_strlen( $keyword ) > 1 ) {
							$wpdb->insert(
								$index_table,
								array(
									'keyword'   => $keyword,
									'vector_id' => $vector_id,
								),
								array( '%s', '%d' )
							);
						}
					}
				}
			}
		}
	}

	/**
	 * 投稿が削除された際に、索引から関連データを削除する
	 * @param int $post_id 投稿ID.
	 */
	public function delete_post_from_index( $post_id ) {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'feas_ai_vectors';
		$index_table   = $wpdb->prefix . 'feas_ai_keyword_index';

		// 削除対象の投稿に紐づくベクトルIDを取得
		$vector_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );

		if ( ! empty( $vector_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $vector_ids ), '%d' ) );

			// 索引テーブルから削除
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$index_table}` WHERE `vector_id` IN ( {$placeholders} )", $vector_ids ) );

			// ベクトルテーブルから削除
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$vectors_table}` WHERE `post_id` = %d", $post_id ) );
		}
	}

	public function delete_on_uninstall_field_html() {
		$option = get_option( 'feas_ai_delete_on_uninstall' );
		?>
		<fieldset>
			<label>
				<input
					type="checkbox"
					name="feas_ai_delete_on_uninstall"
					value="1"
					<?php checked( $option, '1' ); ?>
				/>
				プラグインを削除（アンインストール）する際に、すべてのデータベーステーブルと設定を完全に削除する。
			</label>
			<p class="description">一時的な無効化ではなく、完全にこのプラグインの使用をやめる場合にチェックを入れてください。<br>チェックを外しておくと、プラグインを再インストールした際に、以前の設定と同期データが復元されます。</p>
		</fieldset>
		<?php
	}

	public function display_mode_field_html() {
		$mode = get_option( 'feas_ai_display_mode', 'float' ); // デフォルトはフローティング
		?>
		<fieldset>
			<label>
				<input type="radio" name="feas_ai_display_mode" value="float" <?php checked( 'float', $mode ); ?>>
				<span>フローティングモード（サイトの全ページ右下に自動で表示）</span>
			</label><br>
			<label>
				<input type="radio" name="feas_ai_display_mode" value="shortcode" <?php checked( 'shortcode', $mode ); ?>>
				<span>手動設置モード（ショートコード <code>[feas_ai_chat]</code> で好きな場所に設置）</span>
			</label>
		</fieldset>
		<?php
	}

	public function register_shortcode() {
		add_shortcode( 'feas_ai_chat', array( $this, 'render_chat_shortcode' ) );
	}

	/**
	 * ショートコード [feas_ai_chat] のための処理
	 */
	public function render_chat_shortcode() {
		// 必要なアセットをここで読み込む
		$this->assets->enqueue_assets();
		// HTMLを返す
		return $this->get_chat_ui_html('embed');
	}

	/**
	 * フローティングモードの場合のみ、フッターにチャットUIを出力する
	 */
	public function maybe_render_floating_chat() {
		$mode = get_option( 'feas_ai_display_mode', 'float' );
		if ( 'float' === $mode ) {
			$this->assets->enqueue_assets();
			// ショートコードの処理を再利用して出力
			echo $this->get_chat_ui_html('float');
		}
	}
}
