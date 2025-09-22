<?php
class FEAS_AI_Assets {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * フロントエンド用のスクリプトとスタイルを読み込む
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'feas-ai-chat-style',
			plugin_dir_url( __DIR__ ) . 'assets/css/chat-style.css',
			array(),
			FEAS_AI_VERSION
		);

		wp_enqueue_script(
			'feas-ai-chat-main',
			plugin_dir_url( __DIR__ ) . 'assets/js/chat-main.js',
			array(),
			FEAS_AI_VERSION,
			true
		);

		wp_localize_script(
			'feas-ai-chat-main',
			'feas_ai_ajax_obj', // 接頭辞を変更
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'feas_ai_ajax_nonce' ),
				'home_url' => home_url('/'),
			)
		);
	}
}
