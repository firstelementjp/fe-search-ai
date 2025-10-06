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

	private static $is_rendered = false;
	private $assets_handler;

	public function __construct( $assets_handler ) {
		$this->assets_handler = $assets_handler;

		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_head', [ $this, 'output_dynamic_styles' ] );
		add_action( 'wp_footer', array( $this, 'maybe_render_floating_chat' ) );
	}

	public function register_shortcode() {
		add_shortcode( 'fe_ai_search', array( $this, 'render_chat_shortcode' ) );
	}

	public function render_chat_shortcode() {
		if ( self::$is_rendered ) {
			return '';
		}
		self::$is_rendered = true;

		$this->assets_handler->enqueue_assets();
		return $this->get_chat_ui_html('embed');
	}

	public function maybe_render_floating_chat() {
		if ( self::$is_rendered ) {
			return;
		}

		$options = get_option( 'feas_ai_display_options', [] );
		$fullscreen_page_id = (int) ($options['fullscreen_page_id'] ?? 0);
		$is_fullscreen_page = ( $fullscreen_page_id > 0 && is_page($fullscreen_page_id) );

		if ( $is_fullscreen_page ) {
			self::$is_rendered = true;
			$this->assets_handler->enqueue_assets();
			echo str_replace('class="feas-ai-mode-float"', 'class="feas-ai-mode-float is-fullscreen"', $this->get_chat_ui_html('float'));
			return;
		}

		if ( empty($options['enable_floating_mode']) ) {
			return;
		}

		if ( ! empty( $options['require_login'] ) && ! is_user_logged_in() ) {
			return;
		}

		$is_mobile = wp_is_mobile();
		if ( ($is_mobile && empty($options['display_on_mobile'])) || (!$is_mobile && empty($options['display_on_pc'])) ) {
			return;
		}

		$rules = $options['display_rules'] ?? [];
		$should_display = false;

		$include_ids = array_filter( array_map('intval', explode(',', $rules['include_ids'] ?? '')) );
		$exclude_ids = array_filter( array_map('intval', explode(',', $rules['exclude_ids'] ?? '')) );
		$current_id = get_the_ID();

		if ( ! empty( $include_ids ) ) {
			// Include ID takes priority
			$should_display = ( is_singular() && in_array( $current_id, $include_ids ) );
		} else {
			// Determined by basic rules and post type rules
			if ( is_front_page() && !empty($rules['show_on_front_page']) ) $should_display = true;
			if ( is_archive() && !empty($rules['show_on_archives']) ) $should_display = true;
			if ( is_search() && !empty($rules['show_on_search']) ) $should_display = true;
			if ( is_singular() && !empty($rules['post_types'][get_post_type()]) ) $should_display = true;
		}

		// Exclude ID has the final say
		if ( is_singular() && in_array( $current_id, $exclude_ids ) ) {
			$should_display = false;
		}

		/**
		 * Filters the final boolean decision on whether to display the floating chat UI.
		 *
		 * This hook acts as a final override, allowing developers to implement complex
		 * display logic that cannot be configured through the settings UI, such as
		 * showing the chat only to logged-in users.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $should_display Whether the chat UI should be displayed.
		 */
		if ( apply_filters( 'feas_ai_should_display_chat', $should_display ) ) {
			self::$is_rendered = true;
			$this->assets_handler->enqueue_assets();
			echo $this->get_chat_ui_html('float');
		}
	}

	public function get_chat_ui_html( $mode = 'float' ) {
		$options      = get_option( 'feas_ai_display_options', [] );
		$window_title = $options['window_title'] ?? __( 'AI Search', 'fe-ai-search' );
		$placeholder  = $options['placeholder_text'] ?? __( 'Please enter a question...', 'fe-ai-search' );
		$greeting     = $options['greeting_message'] ?? __( 'Hello! Please ask me anything about the information on this site.', 'fe-ai-search' );
		$submit_text  = $options['submit_button_text'] ?? __( 'Submit', 'fe-ai-search' );

		ob_start();
		?>
		<div id="feas-ai-chat-container" class="feas-ai-mode-<?php echo esc_attr( $mode ); ?>">
			<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

			<div id="feas-ai-chat-bubble">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path></svg>
			</div>

			<div id="feas-ai-chat-window" class="hidden">
				<div id="feas-ai-chat-header">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" id="feas-ai-chat-home-link" class="feas-ai-header-icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"></path></svg>
					</a>
					<h3><?php echo esc_html( $window_title ); ?></h3>
					<div class="feas-ai-header-buttons">
						<button id="feas-ai-chat-fullscreen-toggle" class="feas-ai-header-icon">
							<svg class="icon-maximize" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
							<svg class="icon-minimize" style="display:none;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>
						</button>
						<button id="feas-ai-chat-close" class="feas-ai-header-icon">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
						</button>
					</div>
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
					<div class="feas-ai-privacy-notice" style="font-size: 11px; text-align: center; color: #777; margin-top: 8px;">
						<?php
						$terms_page_id   = $options['terms_page_id'] ?? 0;
						$privacy_page_id = $options['privacy_page_id'] ?? 0;
						$terms_url       = $terms_page_id ? get_permalink( $terms_page_id ) : '';
						$privacy_url     = $privacy_page_id ? get_permalink( $privacy_page_id ) : get_privacy_policy_url();

						$links = [];
						if ( $terms_url ) {
							$links[] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $terms_url ), esc_html__( 'Terms of Service', 'fe-ai-search' ) );
						}
						if ( $privacy_url ) {
							$links[] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $privacy_url ), esc_html__( 'Privacy Policy', 'fe-ai-search' ) );
						}

						if ( ! empty( $links ) ) {
							printf(
								esc_html__( 'By using this chat, you agree to our %s.', 'fe-ai-search' ),
								implode( ' and ', $links )
							);
						}
						?>
					</div>
				</div>
			</div>
		</div>
		<script>
			if (typeof initFEAIChat === 'function') {
				initFEAIChat();
			}
		</script>
		<?php
		return ob_get_clean();
	}

	public function output_dynamic_styles() {
		$options = get_option( 'feas_ai_display_options', [] );
		$key_color = $options['key_color'] ?? '#0073aa';
		?>
		<style>
			:root {
				--feas-ai-key-color: <?php echo esc_attr( $key_color ); ?>;
			}
		</style>
		<?php
	}
}
