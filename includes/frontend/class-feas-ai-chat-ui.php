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

	/**
	 * Generates the complete HTML for the chat UI.
	 *
	 * This method gathers all necessary data, builds the default HTML, and then
	 * passes both through a filter to allow for complete customization by themes or other plugins.
	 *
	 * @since 1.0.0
	 * @param string $mode The display mode ('float', 'fullscreen', or 'embed').
	 * @return string The final, filterable HTML for the chat UI.
	 */
	public function get_chat_ui_html( $mode = 'float' ) {
		// --- 1. Gather all raw data needed to build the UI into a single array ---
		$options             = get_option( 'feas_ai_display_options', [] );
		$locale              = get_locale();
		$is_cjk              = in_array( substr( $locale, 0, 2 ), [ 'ja', 'zh', 'ko' ], true );
		$send_on_shift_enter = $options['send_on_shift_enter'] ?? $is_cjk;
		$terms_page_id       = $options['terms_page_id'] ?? 0;
		$privacy_page_id     = $options['privacy_page_id'] ?? 0;

		$args = [
			'mode'                => $mode,
			'window_title'        => $options['window_title'] ?? __( 'AI Search', 'fe-ai-search' ),
			'greeting_message'    => $options['greeting_message'] ?? __( 'Hello! Please ask me anything about the information on this site.', 'fe-ai-search' ),
			'placeholder_text'    => $options['placeholder_text'] ?? __( 'Please enter a question...', 'fe-ai-search' ),
			'submit_button_text'  => $options['submit_button_text'] ?? __( 'Submit', 'fe-ai-search' ),
			'send_on_shift_enter' => (bool) $send_on_shift_enter,
			'terms_url'           => $terms_page_id ? get_permalink( $terms_page_id ) : '',
			'privacy_url'         => $privacy_page_id ? get_permalink( $privacy_page_id ) : get_privacy_policy_url(),
		];

		// --- 2. Build the default HTML using the data ---
		ob_start();
		?>
		<div id="feas-ai-chat-container" class="feas-ai-mode-<?php echo esc_attr( $args['mode'] ); ?>">
			<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

			<div id="feas-ai-chat-bubble">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path></svg>
			</div>

			<div id="feas-ai-chat-window" class="hidden">
				<div id="feas-ai-chat-header">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" id="feas-ai-chat-home-link" class="feas-ai-header-icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"></path></svg>
					</a>
					<h3><?php echo esc_html( $args['window_title'] ); ?></h3>
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
						<p><?php echo esc_html( $args['greeting_message'] ); ?></p>
					</div>
				</div>
				<div id="feas-ai-chat-footer">
					<form id="feas-ai-chat-form">
						<input type="text" id="feas-ai-chat-input" placeholder="<?php echo esc_attr( $args['placeholder_text'] ); ?>" autocomplete="off">
						<button type="submit"><?php echo esc_html( $args['submit_button_text'] ); ?></button>
					</form>
					<div id="feas-ai-chat-options">
						<div id="feas-ai-privacy-notice">
							<p>
							<?php
							$links = [];
							if ( ! empty( $args['terms_url'] ) ) {
								$links[] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $args['terms_url'] ), esc_html__( 'Terms of Service', 'fe-ai-search' ) );
							}
							if ( ! empty( $args['privacy_url'] ) ) {
								$links[] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $args['privacy_url'] ), esc_html__( 'Privacy Policy', 'fe-ai-search' ) );
							}
							if ( ! empty( $links ) ) {
								printf(
									// Use wp_kses_post to allow the <a> tags.
									wp_kses_post( __( 'By using this chat, you agree to our %s.', 'fe-ai-search' ) ),
									implode( ' ' . esc_html__( 'and', 'fe-ai-search' ) . ' ', $links )
								);
							}
							?>
							</p>
						</div>
						<div id="feas-ai-chat-footer-actions">
							<button id="feas-ai-options-toggle" title="<?php esc_attr_e( 'Settings', 'fe-ai-search' ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
							</button>
							<div id="feas-ai-options-menu" class="hidden">
								<label>
									<input type="checkbox" id="feas-ai-shift-enter-toggle">
									<?php esc_html_e( 'Send on Shift+Enter', 'fe-ai-search' ); ?>
								</label>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<script>
			if (typeof initFEAIChat === 'function' && !document.getElementById('feas-ai-chat-container').dataset.initialized) {
				initFEAIChat();
			}
		</script>
		<?php
		$default_html = ob_get_clean();

		/**
		 * Filters the complete HTML of the chat user interface.
		 *
		 * This allows developers to completely replace or modify the chat UI.
		 * The raw data used to build the HTML is also passed for convenience.
		 *
		 * @since 1.2.0
		 *
		 * @param string $default_html The default HTML string generated by the plugin.
		 * @param array  $args         An associative array of data used to build the HTML
		 * (e.g., 'window_title', 'greeting_message', etc.).
		 */
		return apply_filters( 'feas_ai_chat_ui_html', $default_html, $args );
	}

	/**
	 * Outputs dynamic CSS to the page header based on user settings.
	 *
	 * This method generates CSS for the key color and makes the entire
	 * style block filterable for advanced customization.
	 *
	 * @since 1.0.0
	 */
	public function output_dynamic_styles() {
		$options   = get_option( 'feas_ai_display_options', [] );
		$key_color = $options['key_color'] ?? '#0073aa';

		// Start output buffering to capture the CSS.
		ob_start();
		?>
		<style id="feas-ai-dynamic-styles">
			:root {
				--feas-ai-key-color: <?php echo esc_attr( $key_color ); ?>;
				--feas-ai-key-color-darker: <?php echo esc_attr( $this->adjust_brightness( $key_color, -20 ) ); ?>;
			}
			/* Add any other default dynamic styles here */
			#feas-ai-chat-form button:hover {
				background-color: var(--feas-ai-key-color-darker);
			}
			.feas-ai-message-user p {
				background: var(--feas-ai-key-color);
			}
			.feas-ai-message-user p::after {
				border-left-color: var(--feas-ai-key-color);
			}
		</style>
		<?php
		$default_css = ob_get_clean();

		/**
		 * Filters the dynamic CSS string for the chat UI.
		 *
		 * This allows developers to completely override or extend the dynamic styles
		 * generated by the plugin, such as the key color.
		 *
		 * @since 1.2.0
		 *
		 * @param string $default_css The default CSS string, including the <style> tags.
		 * @param string $key_color   The key color selected by the user in the settings.
		 */
		echo apply_filters( 'feas_ai_dynamic_styles_css', $default_css, $key_color );
	}

	// in includes/frontend/class-feas-ai-chat-ui.php

	/**
	 * Adjusts the brightness of a hexadecimal color code.
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $hex   The hex color code (e.g., '#RRGGBB').
	 * @param int    $steps A positive (lighter) or negative (darker) integer representing the brightness change.
	 * @return string The new, adjusted hex color code.
	 */
	private function adjust_brightness( $hex, $steps ) {
		// Remove '#' if present.
		$hex = str_replace( '#', '', $hex );

		// Handle 3-digit hex codes.
		if ( strlen( $hex ) === 3 ) {
			$r = hexdec( substr( $hex, 0, 1 ) . substr( $hex, 0, 1 ) );
			$g = hexdec( substr( $hex, 1, 1 ) . substr( $hex, 1, 1 ) );
			$b = hexdec( substr( $hex, 2, 1 ) . substr( $hex, 2, 1 ) );
		} else {
			$r = hexdec( substr( $hex, 0, 2 ) );
			$g = hexdec( substr( $hex, 2, 2 ) );
			$b = hexdec( substr( $hex, 4, 2 ) );
		}

		// Adjust brightness for each color channel.
		$r = max( 0, min( 255, $r + $steps ) );
		$g = max( 0, min( 255, $g + $steps ) );
		$b = max( 0, min( 255, $b + $steps ) );

		// Convert back to hex and return.
		return '#' . str_pad( dechex( $r ), 2, '0', STR_PAD_LEFT )
				 . str_pad( dechex( $g ), 2, '0', STR_PAD_LEFT )
				 . str_pad( dechex( $b ), 2, '0', STR_PAD_LEFT );
	}
}
