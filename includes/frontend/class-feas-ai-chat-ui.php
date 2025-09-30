<?php
/**
 * Handles the rendering of the public-facing chat interface.
 *
 * This file defines the FEAS_AI_Chat_UI class, which is responsible for
 * all frontend output, including the shortcode registration and the conditional
 * display of the floating chat window in the site's footer.
 *
 * @package    fe-ai-search
 * @subpackage Frontend
 * @since      1.0.0
 */

namespace FEAISearch\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The public-facing user interface handler.
 *
 * This class is responsible for rendering the chat bubble and window
 * on the frontend of the site, either automatically as a floating element
 * or manually via a shortcode, based on user settings.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Chat_UI {

	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_footer', array( $this, 'maybe_render_floating_chat' ) );
	}

	public function register_shortcode() {
		add_shortcode( 'feas_ai_chat', array( $this, 'render_chat_shortcode' ) );
	}

	public function render_chat_shortcode() {
		\FEAISearch\Core\FEAS_AI_Assets::enqueue_assets();
		return $this->get_chat_ui_html('embed');
	}

	public function maybe_render_floating_chat() {
		$options = get_option( 'feas_ai_display_options' );
		$display_mode = $options['display_mode'] ?? 'float';

		if ( 'float' !== $display_mode ) {
			return;
		}

		$rules = get_option( 'feas_ai_display_rules', [
			'show_on_front_page' => '1',
			'show_on_archives'   => '1',
		]);

		$should_display = false;

		$include_ids_str = $rules['include_ids'] ?? '';
		$exclude_ids_str = $rules['exclude_ids'] ?? '';

		$include_ids = array_filter( array_map('intval', explode(',', $include_ids_str)) );
		$exclude_ids = array_filter( array_map('intval', explode(',', $exclude_ids_str)) );

		$current_id = get_the_ID();

		// Include ID is the top priority.
		if ( ! empty( $include_ids ) ) {
			if ( is_singular() && in_array( $current_id, $include_ids ) ) {
				$should_display = true;
			}
		} else {
			// Judgment Based on Basic Rules and Post Type Rules
			if ( (is_front_page() && !empty($rules['show_on_front_page'])) ||
				 (is_archive() && !empty($rules['show_on_archives'])) ) {
				$should_display = true;
			}

			$allowed_post_types = [];
			if ( !empty($rules['post_types']) ) {
				foreach ($rules['post_types'] as $pt => $enabled) {
					if ($enabled) {
						$allowed_post_types[] = $pt;
					}
				}
			}
			if ( is_singular($allowed_post_types) ) {
				$should_display = true;
			}
		}

		// Exclude ID has the final decision-making authority.
		if ( is_singular() && in_array( $current_id, $exclude_ids ) ) {
			$should_display = false;
		}

		if ( apply_filters( 'feas_ai_should_display_chat', $should_display ) ) {
			echo $this->get_chat_ui_html('float');
		}
	}

	public function get_chat_ui_html( $mode = 'float' ) {
		$options = get_option('feas_ai_display_options', []);
		$window_title = $options['window_title'] ?? 'サイト内をAI検索';
		$placeholder = $options['placeholder_text'] ?? '質問を入力してください...';
		$greeting = $options['greeting_message'] ?? 'こんにちは！サイト内の情報について、何でも質問してください。';
		$submit_text = $options['submit_button_text'] ?? '送信';
		ob_start(); // Start output buffering
		?>
		<div id="feas-ai-chat-container" class="feas-ai-mode-<?php echo esc_attr( $mode ); ?>">
			<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
			<div id="feas-ai-chat-bubble">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
					<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path>
				</svg>
			</div>
			<div id="feas-ai-chat-window" class="hidden">
				<div id="feas-ai-chat-header">
					<h3><?php echo esc_html( $window_title ); ?></h3>
					<button id="feas-ai-chat-close">&times;</button>
				</div>
				<div id="feas-ai-chat-messages">
					<div class="feas-ai-message feas-ai-message-ai">
						<p><?php echo esc_html( $greeting ); ?></p>
					</div>
				</div>
				<div id="feas-ai-chat-footer">
					<form id="feas-ai-chat-form">
						<input type="text" id="feas-ai-chat-input" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off">
						<button type="submit"><?php echo esc_html( $submit_text ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean(); // Retrieve the contents of the buffer, return it as a string, and close the buffer.
	}
}
