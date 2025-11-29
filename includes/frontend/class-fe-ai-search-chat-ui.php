<?php
/**
 * Handles the rendering of the public-facing chat interface.
 *
 * This file defines the FE_AI_Search_Chat_UI class, which is responsible for
 * all frontend output, including the shortcode registration and the conditional
 * display of the floating chat window in the site's footer.
 *
 * @package    fe-ai-search
 * @subpackage Frontend
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public-facing user interface handler.
 *
 * This class is responsible for rendering the chat bubble and window
 * on the frontend of the site, either automatically as a floating element
 * or manually via a shortcode, based on user settings.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Frontend
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_Chat_UI {

	private $options            = [];
	private static $is_rendered = false;
	private $assets_handler;

	public function __construct( $assets_handler ) {
		$this->options        = get_option( 'fe_ai_search_settings', [] );
		$this->assets_handler = $assets_handler;

		add_action( 'init', [ $this, 'register_shortcode' ] );
		add_action( 'wp_head', [ $this, 'output_dynamic_styles' ] );
		add_action( 'wp_footer', [ $this, 'maybe_render_floating_chat' ] );
	}

	public function register_shortcode() {
		add_shortcode( 'fe_ai_search', [ $this, 'render_chat_shortcode' ] );
	}

	public function render_chat_shortcode() {
		if ( self::$is_rendered ) {
			return '';
		}
		self::$is_rendered = true;

		$this->assets_handler->enqueue_assets();
		return $this->get_chat_ui_html( 'embed' );
	}

	/**
	 * Renders the floating chat UI based on display rules.
	 *
	 * This method checks various conditions (e.g., login status, device,
	 * display rules) to determine if the floating chat bubble should be
	 * output to the page footer.
	 *
	 * @since 1.0.0
	 */
	public function maybe_render_floating_chat() {
		// Prevent double rendering
		if ( self::$is_rendered ) {
			return;
		}

		// Get the correct settings from the master options array
		$floating_options = $this->options['display']['floating'] ?? [];

		// Check basic floating mode conditions
		if ( empty( $floating_options['enable_floating_mode'] ) ) {
			return;
		}
		$is_logged_in            = is_user_logged_in();
		$show_to_logged_in_users = ! empty( $floating_options['show_to_logged_in_users'] );
		$show_to_guests          = ! empty( $floating_options['show_to_guests'] );

		if ( $is_logged_in && ! $show_to_logged_in_users ) {
			return;
		}
		if ( ! $is_logged_in && ! $show_to_guests ) {
			return;
		}

		// Check device visibility
		$is_mobile = wp_is_mobile();
		if ( ( $is_mobile && empty( $floating_options['display_on_mobile'] ) ) || ( ! $is_mobile && empty( $floating_options['display_on_pc'] ) ) ) {
			return;
		}

		// Check display rules
		$rules          = $this->options['display']['floating']['display_rules'] ?? [];
		$should_display = false;

		// Optional: hide on 404 pages when configured via show_on_404 flag.
		if ( is_404() && empty( $rules['show_on_404'] ) ) {
			return;
		}

		$include_ids = array_filter( array_map( 'intval', explode( ',', $rules['include_ids'] ?? '' ) ) );
		$exclude_ids = array_filter( array_map( 'intval', explode( ',', $rules['exclude_ids'] ?? '' ) ) );
		$current_id  = get_the_ID();

		if ( ! empty( $include_ids ) ) {
			// Include ID list takes priority
			$should_display = ( is_singular() && in_array( $current_id, $include_ids ) );
		} else {
			// Otherwise, check against the general rules
			if ( is_front_page() && ! empty( $rules['show_on_front_page'] ) ) {
				$should_display = true;
			}
			if ( is_archive() && ! empty( $rules['show_on_archives'] ) ) {
				$should_display = true;
			}
			if ( is_search() && ! empty( $rules['show_on_search'] ) ) {
				$should_display = true;
			}
			if ( is_singular() ) {
				// First, honor per-post-type rules when defined.
				$post_type = get_post_type();
				if ( ! empty( $rules['post_types'][ $post_type ] ) ) {
					$should_display = true;
				} elseif ( ! empty( $rules['show_on_singular'] ) ) {
					// Fallback: use the global "show_on_singular" flag when no
					// explicit post type rule is configured.
					$should_display = true;
				}
			}
		}

		// Exclude ID list always overrides and acts as a final "no"
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
		if ( apply_filters( 'fe_ai_search_should_display_chat', $should_display ) ) {
			self::$is_rendered = true;
			$this->assets_handler->enqueue_assets();
			echo $this->get_chat_ui_html( 'float' );
		}
	}

	/**
	 * Generates the complete HTML for the chat UI.
	 *
	 * This method gathers all necessary data from the master settings array,
	 * builds the default HTML, and then passes both through a filter to allow
	 * for complete customization by themes or other plugins.
	 *
	 * @since 1.0.0
	 * @param string $mode The display mode ('float', 'fullscreen', or 'embed').
	 * @return string The final, filterable HTML for the chat UI.
	 */
	public function get_chat_ui_html( $mode = 'float' ) {
		// Get settings from the master options array (cached in constructor)
		$ui_options    = $this->options['display']['ui'] ?? [];
		$text_options  = $this->options['display']['text'] ?? [];
		$links_options = $this->options['display']['links'] ?? [];

		$locale              = get_locale();
		$is_cjk              = in_array( substr( $locale, 0, 2 ), [ 'ja', 'zh', 'ko' ], true );
		$send_on_shift_enter = $ui_options['send_on_shift_enter'] ?? $is_cjk;
		$terms_page_id       = $links_options['terms_page_id'] ?? 0;
		$privacy_page_id     = $links_options['privacy_page_id'] ?? 0;

		// Ensure text fields fall back to defaults when empty or not set.
		$window_title = trim( $text_options['window_title'] ?? '' );
		if ( '' === $window_title ) {
			$window_title = __( 'FE Search AI', 'fe-ai-search' );
		}

		$greeting_message = trim( $text_options['greeting_message'] ?? '' );
		if ( '' === $greeting_message ) {
			$greeting_message = __( 'Hello! Please ask me anything about the information on this site.', 'fe-ai-search' );
		}

		$placeholder_text = trim( $text_options['placeholder_text'] ?? '' );
		if ( '' === $placeholder_text ) {
			$placeholder_text = __( 'Please enter a question...', 'fe-ai-search' );
		}

		$submit_button_text = trim( $text_options['submit_button_text'] ?? '' );
		if ( '' === $submit_button_text ) {
			$submit_button_text = __( 'Submit', 'fe-ai-search' );
		}

		// Build the $args array for passing to the filter
		$args = [
			'mode'                => $mode,
			'window_title'        => $window_title,
			'greeting_message'    => $greeting_message,
			'placeholder_text'    => $placeholder_text,
			'submit_button_text'  => $submit_button_text,
			'send_on_shift_enter' => (bool) $send_on_shift_enter,
			'terms_url'           => $terms_page_id ? get_permalink( $terms_page_id ) : '',
			'privacy_url'         => $privacy_page_id ? get_permalink( $privacy_page_id ) : get_privacy_policy_url(),
		];

		// Build the default HTML
		ob_start();
		?>
		<div id="fe_ai_search_chat_container" class="fe-ai-search-mode-<?php echo esc_attr( $args['mode'] ); ?>">
			<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

			<div id="fe_ai_search_chat_bubble">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
					<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path>
				</svg>
			</div>

			<div id="fe_ai_search_chat_window" class="hidden">
				<div id="fe_ai_search_chat_header">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" id="fe_ai_search_chat_home_link" class="fe-ai-search-header-icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
							<path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
						</svg>
					</a>
					<h3><?php echo esc_html( $args['window_title'] ); ?></h3>
					<div class="fe-ai-search-header-buttons">
						<button id="fe_ai_search_chat_fullscreen_toggle" class="fe-ai-search-header-icon">
							<svg class="icon-maximize" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
							</svg>
							<svg class="icon-minimize" style="display:none;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/>
							</svg>
						</button>
						<button id="fe_ai_search_chat_close" class="fe-ai-search-header-icon">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
							</svg>
						</button>
					</div>
				</div>
				<div id="fe_ai_search_chat_messages">
					<div class="fe-ai-search-message fe-ai-search-message-ai">
						<p><?php echo esc_html( $args['greeting_message'] ); ?></p>
					</div>
				</div>
				<div id="fe_ai_search_chat_footer">
					<form id="fe_ai_search_chat_form">
						<textarea id="fe_ai_search_chat_input" placeholder="<?php echo esc_attr( $args['placeholder_text'] ); ?>" autocomplete="off"></textarea>
						<button type="submit"><?php echo esc_html( $args['submit_button_text'] ); ?></button>
					</form>
					<div id="fe_ai_search_chat_options">
						<div id="fe_ai_search_privacy_notice">
							<?php
							$links = [];
							if ( ! empty( $args['terms_url'] ) ) {
								$links[] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $args['terms_url'] ), esc_html__( 'Terms of Service', 'fe-ai-search' ) );
							}
							if ( ! empty( $args['privacy_url'] ) ) {
								$links[] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $args['privacy_url'] ), esc_html__( 'Privacy Policy', 'fe-ai-search' ) );
							}
							if ( ! empty( $links ) ) {
								echo '<p>';
								printf(
									wp_kses_post( __( 'By using this chat, you agree to our %s.', 'fe-ai-search' ) ),
									implode( ' ' . esc_html__( 'and', 'fe-ai-search' ) . ' ', $links )
								);
								echo '</p>';
							}
							?>
						</div>
						<div id="fe_ai_search_chat_footer_actions">
							<button id="fe_ai_search_options_toggle" title="<?php esc_attr_e( 'Settings', 'fe-ai-search' ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
									<path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12-.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/>
								</svg>
							</button>
							<div id="fe_ai_search_options_menu" class="hidden">
								<label for="fe_ai_search_send_mode_toggle">
									<?php esc_html_e( 'Send Key Settings:', 'fe-ai-search' ); ?>
								</label>
								<select id="fe_ai_search_send_mode_toggle">
									<option value="enter">
										<?php esc_html_e( 'Enter', 'fe-ai-search' ); ?>
									</option>
									<option value="shift_enter">
										<?php esc_html_e( 'Shift+Enter', 'fe-ai-search' ); ?>
									</option>
									<option value="cmd_enter">
										<?php esc_html_e( 'Cmd/Ctrl+Enter', 'fe-ai-search' ); ?>
									</option>
								</select>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<script>
			if (typeof initFEAIChat === 'function' && !document.getElementById('fe_ai_search_chat_container').dataset.initialized) {
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
		 * @since 1.0.0
		 *
		 * @param string $default_html The default HTML string generated by the plugin.
		 * @param array  $args         An associative array of data used to build the HTML
		 * (e.g., 'window_title', 'greeting_message', etc.).
		 */
		return apply_filters( 'fe_ai_search_chat_ui_html', $default_html, $args );
	}

	/**
	 * Outputs dynamic CSS to the page header based on user settings.
	 *
	 * This method generates CSS for the key color and makes the entire
	 * style block filterable for advanced customization.
	 * It reads the settings from the class property $this->options,
	 * which is populated in the constructor.
	 *
	 * @since 1.0.0
	 */
	public function output_dynamic_styles() {
		$ui_options = $this->options['display']['ui'] ?? [];
		$key_color  = $ui_options['key_color'] ?? '#0073aa';

		ob_start();
		?>
		<style id="fe_ai_search_dynamic_styles">
			:root {
				--fe-ai-search-key-color: <?php echo esc_attr( $key_color ); ?>;
				--fe-ai-search-key-color-darker: <?php echo esc_attr( $this->adjust_brightness( $key_color, -20 ) ); ?>;
			}
			#fe_ai_search_chat_form button:hover {
				background-color: var(--fe-ai-search-key-color-darker);
			}
			.fe-ai-search-message-user p {
				background: var(--fe-ai-search-key-color);
			}
			.fe-ai-search-message-user p::after {
				border-left-color: var(--fe-ai-search-key-color);
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
		 * @since 1.0.0
		 *
		 * @param string $default_css The default CSS string, including the <style> tags.
		 * @param string $key_color   The key color selected by the user in the settings.
		 */
		echo apply_filters( 'fe_ai_search_dynamic_styles_css', $default_css, $key_color );
	}

	/**
	 * Adjusts the brightness of a hexadecimal color code.
	 *
	 * @since 1.0.0
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
