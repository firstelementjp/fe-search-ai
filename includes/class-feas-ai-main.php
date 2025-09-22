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
	}

	public function add_admin_menu() {
		add_options_page(
			'FE AI Search Settings',
			'FE AI Search',
			'manage_options',
			'fe-ai-search',
			array( $this, 'settings_page_html' )
		);
	}

	public function settings_init() {
		$settings_group = 'feas-ai-settings';
		$page_slug      = 'fe-ai-search';

		register_setting( $settings_group, 'feas_ai_openai_api_key' );
		register_setting( $settings_group, 'feas_ai_api_provider' );
		register_setting( $settings_group, 'feas_ai_google_api_key' );

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
					 'posts_per_page' => 5,
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
}
