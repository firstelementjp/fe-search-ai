<?php
/**
 * Registers all plugin assets (CSS and JavaScript).
 *
 * This file defines the fe_ai_search_Assets class, which is responsible for
 * enqueuing all styles and scripts for both the public-facing chat UI
 * and the admin settings pages.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The assets handler class.
 *
 * This class is responsible for registering and enqueueing all the
 * assets, styles, and scripts that the plugin uses.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Core
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Assets {

	private $options = [];

	public function __construct() {
		$this->options = get_option( 'fe_ai_search_settings', [] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Load scripts and styles for the front end.
	 */
	public function enqueue_assets() {
		// Pro settings (used for rate limiting and privacy configuration).
		$pro_options = [];
		if ( $is_license_active && class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' ) ) {
			$pro_options = get_option( 'fe_ai_search_pro_settings', [] );
		}

		// License settings values
		$license_data      = get_option( 'fe_ai_search_license', [] );
		$status            = $license_data['status'] ?? 'inactive';
		$data              = $license_data['data'] ?? [];
		$product_id        = isset( $data['productId'] ) ? (int) $data['productId'] : 0;
		$is_license_active = ( 'active' === $status && 65 === $product_id ); // 65 is the product ID for the Pro add-on.

		// UI settings values
		$ui_options        = $this->options['display']['ui'] ?? [];
		$enable_css        = $ui_options['enable_css'] ?? true;
		$enable_js         = $ui_options['enable_js'] ?? true;
		$animation_speed   = $ui_options['animation_speed'] ?? 3;
		$send_mode         = $ui_options['send_mode'] ?? 'enter';
		$key_color         = $ui_options['key_color'] ?? '#cee8ff';
		$background_color  = $ui_options['background_color'] ?? '#f5f5f5';
		$text_color        = $ui_options['text_color'] ?? '#3a424f';
		$use_gradient      = isset( $ui_options['use_gradient'] ) ? (bool) $ui_options['use_gradient'] : true;
		$key_color         = sanitize_hex_color( $key_color ) ?: '#0073aa';
		$background_color  = sanitize_hex_color( $background_color ) ?: '#f5f5f5';
		$text_color        = sanitize_hex_color( $text_color ) ?: '#111111';
		$colors            = $this->compute_chat_colors( $key_color, $background_color, $text_color, $use_gradient );
		$border_color      = $colors['border'];
		$input_bg_hex      = $colors['input_bg'];
		$user_bubble_hex   = $colors['user_bubble'];
		$bg_top_hex        = $colors['bg_top'];
		$bg_bottom_hex     = $colors['bg_bottom'];
		$accent_top_hex    = $colors['accent_top'];
		$accent_bottom_hex = $colors['accent_bottom'];

		// Prepare initial defaults for privacy consent banner.
		$privacy_config = [
			'enable_consent'  => false,
			'consent_message' => '',
		];

		// Build the privacy consent configuration from Pro settings, if available.
		if ( ! empty( $pro_options ) ) {
			$privacy_options = $pro_options['privacy'] ?? [];
			$enable_consent  = ! empty( $privacy_options['enable_consent'] );
			$consent_tpl     = $privacy_options['consent_message'] ?? '';

			if ( $enable_consent && ! empty( $consent_tpl ) ) {
				$links           = $this->options['display']['links'] ?? [];
				$terms_page_id   = isset( $links['terms_page_id'] ) ? (int) $links['terms_page_id'] : 0;
				$privacy_page_id = isset( $links['privacy_page_id'] ) ? (int) $links['privacy_page_id'] : 0;
				if ( $terms_page_id && $privacy_page_id ) {
					$terms_url       = get_permalink( $terms_page_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$privacy_url     = get_permalink( $privacy_page_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$consent_message = sprintf( $consent_tpl, esc_url( $terms_url ), esc_url( $privacy_url ) );
					$privacy_config  = [
						'enable_consent'  => true,
						'consent_message' => wp_kses_post( $consent_message ),
					];
				}
			}
		}

		// Rate limit settings values (base defaults).
		$ip_limit_count     = 50;  // Default: 50 requests per IP address per hour.
		$global_limit_count = 1000; // Default: 1000 requests globally per hour.
		$notify_threshold   = 80;   // Default: notify when 80% of the limit is reached.

		if ( ! empty( $pro_options ) ) {
			$rate_limit_options = $pro_options['security']['rate_limit'] ?? [];
			$ip_limit_count     = $rate_limit_options['ip_limit_count'] ?? $ip_limit_count;
			$global_limit_count = $rate_limit_options['global_limit_count'] ?? $global_limit_count;
			$notify_threshold   = $rate_limit_options['notify_threshold'] ?? $notify_threshold;
		}

		$default_limits = [
			'ip_limit_count'     => (int) $ip_limit_count,
			'global_limit_count' => (int) $global_limit_count,
			'notify_threshold'   => (int) $notify_threshold,
			'notify_email'       => get_option( 'admin_email' ),
		];

		/**
		 * Filter the final rate limit configuration.
		 *
		 * Allows external code (themes/plugins) to adjust the rate limit settings
		 * using $default_limits as the base configuration.
		 */
		$rate_limit_config = apply_filters( 'fe_ai_search_rate_limit_settings', $default_limits );

		// Ensure any missing keys are filled with defaults to avoid notices/warnings.
		$rate_limit_config  = wp_parse_args( $rate_limit_config, $default_limits );
		$ip_limit_count     = (int) $rate_limit_config['ip_limit_count'];
		$global_limit_count = (int) $rate_limit_config['global_limit_count'];
		$notify_threshold   = (int) $rate_limit_config['notify_threshold'];

		// Enqueue frontend styles and scripts for the chat UI.
		$use_unminified = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$frontend_css   = $use_unminified ? 'assets/css/frontend-styles.css' : 'assets/css/frontend-styles.min.css';
		$frontend_js    = $use_unminified ? 'assets/js/frontend-scripts.js' : 'assets/js/frontend-scripts.min.js';

		if ( $enable_css ) {
			wp_enqueue_style(
				'fe-ai-search-frontend-styles',
				plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . $frontend_css,
				[],
				FE_AI_SEARCH_VERSION
			);

			$color_css = sprintf(
				':root{' .
				'--feais-accent:%1$s;' .
				'--feais-bg:%2$s;' .
				'--feais-text:%3$s;' .
				'--feais-border:%4$s;' .
				'--feais-bg-top:%5$s;' .
				'--feais-bg-bottom:%6$s;' .
				'--feais-accent-top:%7$s;' .
				'--feais-accent-bottom:%8$s;' .
				'--feais-input-bg:%9$s;' .
				'--feais-user-bubble-bg:%10$s;' .
				'--fe-ai-search-key-color:var(--feais-accent);' .
				'}',
				$key_color,
				$background_color,
				$text_color,
				$border_color,
				$bg_top_hex,
				$bg_bottom_hex,
				$accent_top_hex,
				$accent_bottom_hex,
				$input_bg_hex,
				$user_bubble_hex
			);

			/**
			 * Filters the inline CSS that defines the core chat color variables.
			 *
			 * This allows advanced customization of the base CSS variables used by
			 * the frontend chat UI (e.g. for light/dark theme switching).
			 *
			 * @since 1.0.0
			 *
			 * @param string $color_css The generated inline CSS string.
			 * @param array  $colors    Array of normalized hex colors with keys
			 *                          "accent", "background", and "text".
			 */
			$color_css = apply_filters(
				'fe_ai_search_frontend_color_css',
				$color_css,
				[
					'accent'         => $key_color,
					'background'     => $background_color,
					'text'           => $text_color,
					'border'         => $border_color,
					'bg_top'         => $bg_top_hex,
					'bg_bottom'      => $bg_bottom_hex,
					'accent_top'     => $accent_top_hex,
					'accent_bottom'  => $accent_bottom_hex,
					'input_bg'       => $input_bg_hex,
					'user_bubble_bg' => $user_bubble_hex,
				]
			);

			if ( ! empty( $color_css ) ) {
				wp_add_inline_style( 'fe-ai-search-frontend-styles', $color_css );
			}
		}

		// If default plugin JavaScript is disabled, do not enqueue any frontend script.
		if ( ! $enable_js ) {
			return;
		}

		wp_enqueue_script(
			'fe-ai-search-frontend-scripts',
			plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . $frontend_js,
			[ 'wp-i18n' ],
			FE_AI_SEARCH_VERSION,
			true
		);

		wp_set_script_translations(
			'fe-ai-search-frontend-scripts',
			'fe-ai-search',
			plugin_dir_path( FE_AI_SEARCH_PLUGIN_FILE ) . 'languages'
		);

		// Pass data to JavaScript.
		$rate_limit_message = apply_filters(
			'fe_ai_search_rate_limit_message',
			__( '(You have reached the request limit. Please wait a while before trying again.)', 'fe-ai-search' )
		);

		// Expose configuration and runtime data to the frontend chat script.
		wp_localize_script(
			'fe-ai-search-frontend-scripts',
			'fe_ai_search_ajax_obj',
			[
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'rest_url'           => rest_url( 'fe-ai-search/v1/stream' ),
				'rest_nonce'         => wp_create_nonce( 'wp_rest' ),
				'nonce'              => wp_create_nonce( 'fe_ai_search_ajax_nonce' ),
				'animation_speed'    => (int) $animation_speed,
				'is_pro_active'      => class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' ),
				'is_license_active'  => $is_license_active,
				'ip_limit_count'     => (int) $ip_limit_count,
				'send_mode'          => $send_mode,
				'privacy'            => $privacy_config,
				'rate_limit_message' => $rate_limit_message,
			]
		);
	}

	/**
	 * Computes all derived chat UI colors (CSS variables) from the base colors.
	 *
	 * This method encapsulates the color math used to generate the gradient
	 * endpoints, input background, user bubble background, and border color.
	 *
	 * @param string $key_color        Sanitized HEX key color.
	 * @param string $background_color Sanitized HEX background color.
	 * @param string $text_color       Sanitized HEX text color.
	 * @param bool   $use_gradient     Whether gradients are enabled.
	 * @return array{
	 *     border: string,
	 *     input_bg: string,
	 *     user_bubble: string,
	 *     bg_top: string,
	 *     bg_bottom: string,
	 *     accent_top: string,
	 *     accent_bottom: string
	 * }
	 */
	private function compute_chat_colors( string $key_color, string $background_color, string $text_color, bool $use_gradient ): array {
		$border_color = $this->generate_border_color_from_background( $background_color );

		// Convert HEX colors to RGB arrays so we can perform numeric adjustments
		// (lighten/darken, mix accent color, and build gradient endpoints).
		$bg_rgb     = $this->hex_to_rgb( $background_color );
		$accent_rgb = $this->hex_to_rgb( $key_color );
		if ( ! $bg_rgb ) {
			$bg_rgb = [ 240, 240, 240 ];
		}
		if ( ! $accent_rgb ) {
			$accent_rgb = [ 0, 115, 170 ];
		}
		list( $bg_r, $bg_g, $bg_b )             = $bg_rgb;
		list( $accent_r, $accent_g, $accent_b ) = $accent_rgb;

		// Calculate the input field background color by making the background color about 10% lighter.
		$input_bg_r   = (int) round( $bg_r + ( 255 - $bg_r ) * 0.1 );
		$input_bg_g   = (int) round( $bg_g + ( 255 - $bg_g ) * 0.1 );
		$input_bg_b   = (int) round( $bg_b + ( 255 - $bg_b ) * 0.1 );
		$input_bg_hex = sanitize_hex_color( sprintf( '#%02x%02x%02x', $input_bg_r, $input_bg_g, $input_bg_b ) ) ?: $background_color;

		// Calculate the user chat bubble color by mixing a small amount (5%) of the accent color into the input color.
		$user_bubble_mix = 0.05;
		$user_bubble_r   = (int) round( $input_bg_r * ( 1 - $user_bubble_mix ) + $accent_r * $user_bubble_mix );
		$user_bubble_g   = (int) round( $input_bg_g * ( 1 - $user_bubble_mix ) + $accent_g * $user_bubble_mix );
		$user_bubble_b   = (int) round( $input_bg_b * ( 1 - $user_bubble_mix ) + $accent_b * $user_bubble_mix );
		$user_bubble_hex = sanitize_hex_color( sprintf( '#%02x%02x%02x', $user_bubble_r, $user_bubble_g, $user_bubble_b ) ) ?: $key_color;

		$bg_factor_light     = 0.1;
		$bg_factor_dark      = 0.05;
		$accent_factor_light = 0.05;
		$accent_factor_dark  = 0.05;

		$bg_top_r = (int) round( $bg_r * ( 1 - $bg_factor_dark ) );
		$bg_top_g = (int) round( $bg_g * ( 1 - $bg_factor_dark ) );
		$bg_top_b = (int) round( $bg_b * ( 1 - $bg_factor_dark ) );

		// Calculate the bright side of the background color by slightly rotating the hue of the original background color and mixing in 15% white.
		list( $h, $s, $l )       = $this->rgb_to_hsl( $bg_r, $bg_g, $bg_b );
		$h                       = fmod( ( $h + 3.0 ), 360.0 );
		list( $h_r, $h_g, $h_b ) = $this->hsl_to_rgb( $h, $s, $l );
		$bg_bot_r                = (int) round( $h_r + ( 255 - $h_r ) * $bg_factor_light );
		$bg_bot_g                = (int) round( $h_g + ( 255 - $h_g ) * $bg_factor_light );
		$bg_bot_b                = (int) round( $h_b + ( 255 - $h_b ) * $bg_factor_light );

		// Dark side of the accent (bottom-left): slightly rotate the accent hue and darken it.
		list( $ah, $as, $al )          = $this->rgb_to_hsl( $accent_r, $accent_g, $accent_b );
		$ah                            = fmod( ( $ah + 3.0 ), 360.0 );
		list( $a_h_r, $a_h_g, $a_h_b ) = $this->hsl_to_rgb( $ah, $as, $al );
		$accent_top_r                  = (int) round( $a_h_r * ( 1 - $accent_factor_dark ) );
		$accent_top_g                  = (int) round( $a_h_g * ( 1 - $accent_factor_dark ) );
		$accent_top_b                  = (int) round( $a_h_b * ( 1 - $accent_factor_dark ) );

		// Bright side of the accent (top-right): slightly mix white into the original accent color to brighten it.
		$accent_bot_r = (int) round( $accent_r + ( 255 - $accent_r ) * $accent_factor_light );
		$accent_bot_g = (int) round( $accent_g + ( 255 - $accent_g ) * $accent_factor_light );
		$accent_bot_b = (int) round( $accent_b + ( 255 - $accent_b ) * $accent_factor_light );

		$bg_top_hex        = sanitize_hex_color( sprintf( '#%02x%02x%02x', $bg_top_r, $bg_top_g, $bg_top_b ) ) ?: $background_color;
		$bg_bottom_hex     = sanitize_hex_color( sprintf( '#%02x%02x%02x', $bg_bot_r, $bg_bot_g, $bg_bot_b ) ) ?: $background_color;
		$accent_top_hex    = sanitize_hex_color( sprintf( '#%02x%02x%02x', $accent_top_r, $accent_top_g, $accent_top_b ) ) ?: $key_color;
		$accent_bottom_hex = sanitize_hex_color( sprintf( '#%02x%02x%02x', $accent_bot_r, $accent_bot_g, $accent_bot_b ) ) ?: $key_color;

		if ( ! $use_gradient ) {
			$bg_top_hex        = $background_color;
			$bg_bottom_hex     = $background_color;
			$accent_top_hex    = $key_color;
			$accent_bottom_hex = $key_color;
		}

		return [
			'border'        => $border_color,
			'input_bg'      => $input_bg_hex,
			'user_bubble'   => $user_bubble_hex,
			'bg_top'        => $bg_top_hex,
			'bg_bottom'     => $bg_bottom_hex,
			'accent_top'    => $accent_top_hex,
			'accent_bottom' => $accent_bottom_hex,
		];
	}

	/**
	 * Automatically generates a border color from the background color.
	 * Returns a slightly lighter color for dark backgrounds and a slightly darker
	 * color for light backgrounds.
	 *
	 * @param string $background_hex Sanitized HEX color (#rrggbb).
	 * @return string HEX color (#rrggbb).
	 */
	private function generate_border_color_from_background( $background_hex ) {
		$rgb = $this->hex_to_rgb( $background_hex );
		if ( ! $rgb ) {
			return '#cccccc';
		}

		list( $r, $g, $b ) = $rgb;
		$r_n               = $r / 255;
		$g_n               = $g / 255;
		$b_n               = $b / 255;

		$luminance = 0.2126 * $r_n + 0.7152 * $g_n + 0.0722 * $b_n;
		$factor    = 0.2;

		if ( $luminance < 0.5 ) {
			$r_mix = (int) round( $r + ( 255 - $r ) * $factor );
			$g_mix = (int) round( $g + ( 255 - $g ) * $factor );
			$b_mix = (int) round( $b + ( 255 - $b ) * $factor );
		} else {
			$r_mix = (int) round( $r * ( 1 - $factor ) );
			$g_mix = (int) round( $g * ( 1 - $factor ) );
			$b_mix = (int) round( $b * ( 1 - $factor ) );
		}

		$hex = sprintf( '#%02x%02x%02x', $r_mix, $g_mix, $b_mix );
		$hex = sanitize_hex_color( $hex );
		if ( ! $hex ) {
			$hex = '#cccccc';
		}

		return $hex;
	}

	/**
	 * Converts a HEX color (#rgb / #rrggbb) to an RGB array [r, g, b].
	 * Returns null for invalid values.
	 *
	 * @param string $hex HEX color.
	 * @return array<int,int>|null
	 */
	private function hex_to_rgb( $hex ) {
		$hex = trim( (string) $hex );
		if ( 0 === strpos( $hex, '#' ) ) {
			$hex = substr( $hex, 1 );
		}

		if ( 3 === strlen( $hex ) ) {
			$r = hexdec( str_repeat( $hex[0], 2 ) );
			$g = hexdec( str_repeat( $hex[1], 2 ) );
			$b = hexdec( str_repeat( $hex[2], 2 ) );
		} elseif ( 6 === strlen( $hex ) ) {
			$r = hexdec( substr( $hex, 0, 2 ) );
			$g = hexdec( substr( $hex, 2, 2 ) );
			$b = hexdec( substr( $hex, 4, 2 ) );
		} else {
			return null;
		}

		return [ $r, $g, $b ];
	}

	/**
	 * Converts RGB (0-255) to HSL (H:0-360, S:0-1, L:0-1).
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 * @return array{0:float,1:float,2:float} [H, S, L]
	 */
	private function rgb_to_hsl( $r, $g, $b ) {
		$r_n = $r / 255;
		$g_n = $g / 255;
		$b_n = $b / 255;

		$max = max( $r_n, $g_n, $b_n );
		$min = min( $r_n, $g_n, $b_n );
		$h   = 0.0;
		$l   = ( $max + $min ) / 2;

		if ( $max === $min ) {
			$s = 0.0;
		} else {
			$delta = $max - $min;
			$s     = ( $l > 0.5 ) ? ( $delta / ( 2 - $max - $min ) ) : ( $delta / ( $max + $min ) );

			if ( $max === $r_n ) {
				$h = ( ( $g_n - $b_n ) / $delta ) + ( $g_n < $b_n ? 6 : 0 );
			} elseif ( $max === $g_n ) {
				$h = ( ( $b_n - $r_n ) / $delta ) + 2;
			} else {
				$h = ( ( $r_n - $g_n ) / $delta ) + 4;
			}
			$h *= 60;
		}

		return [ $h, $s, $l ];
	}

	/**
	 * Converts HSL (H:0-360, S:0-1, L:0-1) to RGB (0-255).
	 *
	 * @param float $h Hue.
	 * @param float $s Saturation.
	 * @param float $l Lightness.
	 * @return array{0:int,1:int,2:int} [R, G, B]
	 */
	private function hsl_to_rgb( $h, $s, $l ) {
		$h = fmod( (float) $h, 360.0 );
		if ( $h < 0 ) {
			$h += 360.0;
		}
		$h /= 360.0;

		if ( 0.0 === $s ) {
			$r = $g = $b = (int) round( $l * 255 );
			return [ $r, $g, $b ];
		}

		$q = ( $l < 0.5 ) ? ( $l * ( 1 + $s ) ) : ( $l + $s - $l * $s );
		$p = 2 * $l - $q;

		$h_k = [ $h + 1 / 3, $h, $h - 1 / 3 ];
		$rgb = [];
		foreach ( $h_k as $hk ) {
			if ( $hk < 0 ) {
				$hk += 1;
			} elseif ( $hk > 1 ) {
				$hk -= 1;
			}

			if ( $hk < 1 / 6 ) {
				$val = $p + ( $q - $p ) * 6 * $hk;
			} elseif ( $hk < 1 / 2 ) {
				$val = $q;
			} elseif ( $hk < 2 / 3 ) {
				$val = $p + ( $q - $p ) * ( 2 / 3 - $hk ) * 6;
			} else {
				$val = $p;
			}
			$rgb[] = (int) round( $val * 255 );
		}

		return [ $rgb[0], $rgb[1], $rgb[2] ];
	}
}
