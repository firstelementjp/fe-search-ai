<?php
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
			<form method="post" action="">
				<h2>コンテンツの同期</h2>
				<p>サイト内の投稿や固定ページをAIが検索できるように、データを準備します。</p>
				<?php wp_nonce_field( 'feas_ai_sync_nonce' ); ?>
				<button type="submit" name="start_sync" class="button button-primary">同期を開始する</button>
			</form>
			<?php
			 if ( isset( $_POST['start_sync'] ) && check_admin_referer( 'feas_ai_sync_nonce' ) ) {
				 global $wpdb;
				 $table_name = $wpdb->prefix . 'feas_ai_vectors';
				 $args = array(
					 'post_type'      => array( 'post', 'page' ),
					 'post_status'    => 'publish',
					 'posts_per_page' => 10,
				 );
				 $all_posts = get_posts( $args );

				if ( empty( $all_posts ) ) {
					echo '<div class="notice notice-warning is-dismissible"><p>同期対象のコンテンツが見つかりませんでした。</p></div>';
					return;
				}

				 echo '<h3>同期処理結果</h3><dl>';
				 foreach ( $all_posts as $post ) {
					 echo '<dt><strong>■ ' . esc_html( $post->post_title ) . '</strong></dt>';
					 $wpdb->delete( $table_name, array( 'post_id' => $post->ID ), array( '%d' ) );

					 $content = strip_shortcodes( $post->post_content );
					 $content = wp_strip_all_tags( $content );
					 $raw_chunks = explode( "\n\n", $content );
					 $valid_chunks = array_filter( array_map( 'trim', $raw_chunks ) );

					 if ( empty( $valid_chunks ) ) {
						 echo '<dd>→ コンテンツが空のためスキップしました。</dd>';
						 continue;
					 }

					 // ★ 別クラスのメソッド呼び出し方を修正
					 $embedding_response = $this->ajax->get_embeddings_via_selected_provider( array_values($valid_chunks) );

					 echo '<dd><ul>';
					 if ( is_wp_error( $embedding_response ) ) {
						 $error_message = $embedding_response->get_error_message();
						 echo '<li style="color: red;">APIエラー: ' . esc_html( $error_message ) . '</li>';
					 } else {
						 $vectors_data = $embedding_response['data'];
						 $saved_count = 0;
						 foreach ( $vectors_data as $index => $vector_item ) {
							 $result = $wpdb->insert(
								 $table_name,
								 array(
									 'post_id'       => $post->ID,
									 'chunk_index'   => $index,
									 'content_chunk' => array_values($valid_chunks)[$index],
									 'vector_data'   => json_encode( $vector_item['embedding'] ),
									 'created_at'    => current_time( 'mysql' ),
								 ),
								 array( '%d', '%d', '%s', '%s', '%s' )
							 );
							 if ( $result ) { $saved_count++; }
						 }
						 echo '<li>' . $saved_count . '個のベクトルデータをデータベースに保存しました。💾</li>';
					 }
					 echo '</ul></dd>';
				 }
				 echo '</dl>';
			 }
			?>
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
}
