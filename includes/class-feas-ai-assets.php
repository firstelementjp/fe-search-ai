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

		// PHPからJavaScriptへ変数を渡す
		wp_localize_script(
			'feas-ai-chat-main',
			'feas_ai_ajax_obj',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),    // ログ保存用
				'rest_url'   => rest_url( 'feas-ai/v1/stream' ), // ストリーミング用
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),      // REST API用Nonce
				'nonce'      => wp_create_nonce( 'feas_ai_ajax_nonce' ),
			)
		);
	}
}
