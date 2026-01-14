<?php
/**
 * Initializes functions related to the plugin management screen.
 *
 * Defines the menu structure of the management screen
 * and serves the role of loading management classes such as settings pages.
 *
 * @package    fe-search-ai
 * @subpackage Admin
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FESearchAI\Admin\FE_Search_AI_Settings;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @since      1.0.0
 * @package    fe-search-ai
 * @subpackage Admin
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Admin {

	public function __construct() {

		new FE_Search_AI_Settings();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_head', [ $this, 'maybe_reposition_settings_errors' ] );
	}

	/**
	 * Render the settings page header.
	 *
	 * @param bool $is_pro Whether Pro license is active.
	 * @return void
	 */
	public static function render_plugin_header( $is_pro = false ) {
		$locale = get_user_locale();

		$docs_url = 'https://fe-search.com/docs/ai';
		if ( 'ja' === $locale || 'ja_JP' === $locale ) {
			$docs_url = 'https://fe-search.com/jp/docs/ai';
		}

		$forum_url = 'https://fe-search.com/en-forums/ai';
		if ( 'ja' === $locale || 'ja_JP' === $locale ) {
			$forum_url = 'https://fe-search.com/jp-forums/ai';
		}
		?>
		<div id="plugin_header">
			<div id="plugin_header_upper">
				<div id="plugin_header_title">FE Search <span>AI</span>
				<?php
				if ( $is_pro ) :
					?>
					<span class="pro-badge">Pro</span><?php endif; ?></div>
				<a href="https://www.firstelement.co.jp/" id="plugin_logo" target="_blank" title="<?php esc_attr_e( 'Go to the developer\'s website', 'fe-search-ai' ); ?>">
					<img src="<?php echo esc_url( plugin_dir_url( FE_SEARCH_AI_PLUGIN_FILE ) . '/assets/images/logo-feas-white-shadow-s@2x-min.png' ); ?>" width="106" height="27">
				</a>
			</div>
			<div id="plugin_version">
				version <?php echo esc_html( FE_SEARCH_AI_VERSION ); ?>
			</div>
			<div id="plugin_support">
				<a href="<?php echo esc_url( $docs_url ); ?>"
					target="_blank"
					title="<?php esc_attr_e( 'Go to the instruction manual', 'fe-search-ai' ); ?>">
					<?php esc_html_e( 'Documentation', 'fe-search-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( $forum_url ); ?>"
					target="_blank"
					title="<?php esc_attr_e( 'Go to a forum', 'fe-search-ai' ); ?>">
					<?php esc_html_e( 'Forums', 'fe-search-ai' ); ?>
				</a>
				<a href="https://github.com/firstelementjp/fe-search-ai"
					target="_blank"
					title="<?php esc_attr_e( 'Go to GitHub repository', 'fe-search-ai' ); ?>"
					class="icon icon_gh">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 20 20"
						width="16"
						height="16"
					>
						<g transform="translate(-140 -7559)" fill="currentColor" fill-rule="evenodd">
							<g transform="translate(56 160)">
								<path d="M94,7399 C99.523,7399 104,7403.59 104,7409.253 C104,7413.782 101.138,7417.624 97.167,7418.981 C96.66,7419.082 96.48,7418.762 96.48,7418.489 C96.48,7418.151 96.492,7417.047 96.492,7415.675 C96.492,7414.719 96.172,7414.095 95.813,7413.777 C98.04,7413.523 100.38,7412.656 100.38,7408.718 C100.38,7407.598 99.992,7406.684 99.35,7405.966 C99.454,7405.707 99.797,7404.664 99.252,7403.252 C99.252,7403.252 98.414,7402.977 96.505,7404.303 C95.706,7404.076 94.85,7403.962 94,7403.958 C93.15,7403.962 92.295,7404.076 91.497,7404.303 C89.586,7402.977 88.746,7403.252 88.746,7403.252 C88.203,7404.664 88.546,7405.707 88.649,7405.966 C88.01,7406.684 87.619,7407.598 87.619,7408.718 C87.619,7412.646 89.954,7413.526 92.175,7413.785 C91.889,7414.041 91.63,7414.493 91.54,7415.156 C90.97,7415.418 89.522,7415.871 88.63,7414.304 C88.63,7414.304 88.101,7413.319 87.097,7413.247 C87.097,7413.247 86.122,7413.234 87.029,7413.87 C87.029,7413.87 87.684,7414.185 88.139,7415.37 C88.139,7415.37 88.726,7417.2 91.508,7416.58 C91.513,7417.437 91.522,7418.245 91.522,7418.489 C91.522,7418.76 91.338,7419.077 90.839,7418.982 C86.865,7417.627 84,7413.783 84,7409.253 C84,7403.59 88.478,7399 94,7399" />
							</g>
						</g>
					</svg>
				</a>
				<a href="https://www.youtube.com/@firstelementjp"
					target="_blank"
					title="<?php esc_attr_e( 'Go to a YouTube channel', 'fe-search-ai' ); ?>"
					class="icon icon_yt">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 32 32"
						width="20"
						height="20"
					>
						<path
							fill="currentColor"
							d="M29.41,9.26a3.5,3.5,0,0,0-2.47-2.47C24.76,6.2,16,6.2,16,6.2s-8.76,0-10.94.59A3.5,3.5,0,0,0,2.59,9.26,36.13,36.13,0,0,0,2,16a36.13,36.13,0,0,0,.59,6.74,3.5,3.5,0,0,0,2.47,2.47C7.24,25.8,16,25.8,16,25.8s8.76,0,10.94-.59a3.5,3.5,0,0,0,2.47-2.47A36.13,36.13,0,0,0,30,16a36.13,36.13,0,0,0-.59-6.74ZM13.2,20.2V11.8L20.47,16Z"
						/>
					</svg>
				</a>
				<a href="https://x.com/feas_wp/"
					target="_blank"
					title="<?php esc_attr_e( 'Go to X', 'fe-search-ai' ); ?>"
					class="icon icon_tw">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 1226.37 1226.37"
						width="20"
						height="20"
					>
						<path
							fill="currentColor"
							d="m727.348 519.284 446.727-519.284h-105.86l-387.893 450.887-309.809-450.887h-357.328l468.492 681.821-468.492 544.549h105.866l409.625-476.152 327.181 476.152h357.328l-485.863-707.086zm-144.998 168.544-47.468-67.894-377.686-540.24h162.604l304.797 435.991 47.468 67.894 396.2 566.721h-162.604l-323.311-462.446z"
						/>
					</svg>
				</a>
				<a href="https://www.facebook.com/firstelementjp/"
					target="_blank"
					title="<?php esc_attr_e( 'Go to Facebook page', 'fe-search-ai' ); ?>"
					class="icon icon_fb">
				</a>
				<a href="https://fe-search.com/contact/"
					target="_blank"
					title="<?php esc_attr_e( 'Go to contact form', 'fe-search-ai' ); ?>"
					class="icon icon_mail">
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Load scripts and styles for the admin panel.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$allowed_hooks = [
			'toplevel_page_fe-search-ai',
		];
		$allowed_hooks = apply_filters( 'fe_search_ai_admin_allowed_hooks', $allowed_hooks );

		if ( ! in_array( $hook_suffix, $allowed_hooks ) ) {
			return;
		}

		$use_unminified = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$admin_css      = $use_unminified ? 'assets/css/admin-styles.css' : 'assets/css/admin-styles.min.css';
		$admin_js       = 'assets/js/admin-scripts.js';

		// Color picker (Pickr) styles.
		wp_enqueue_style(
			'fe-search-ai-pickr',
			'https://cdn.jsdelivr.net/npm/@simonwep/pickr/dist/themes/classic.min.css',
			[],
			FE_SEARCH_AI_VERSION
		);
		wp_enqueue_script(
			'fe-search-ai-pickr',
			plugin_dir_url( FE_SEARCH_AI_PLUGIN_FILE ) . 'assets/vendor/pickr.min.js',
			[],
			FE_SEARCH_AI_VERSION,
			true
		);
		wp_enqueue_style(
			'codemirror-css',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.css'
		);
		wp_enqueue_style(
			'fe-search-ai-admin-style',
			plugin_dir_url( FE_SEARCH_AI_PLUGIN_FILE ) . $admin_css,
			[],
			FE_SEARCH_AI_VERSION
		);

		wp_enqueue_script(
			'codemirror-js',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.js',
			[],
			false,
			true
		);
		wp_enqueue_script(
			'codemirror-markdown',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/markdown/markdown.min.js',
			[ 'codemirror-js' ],
			false,
			true
		);
		wp_enqueue_script(
			'fe-search-ai-admin-sync',
			plugin_dir_url( FE_SEARCH_AI_PLUGIN_FILE ) . $admin_js,
			[ 'wp-i18n', 'codemirror-js', 'fe-search-ai-pickr' ],
			FE_SEARCH_AI_VERSION,
			true
		);

		wp_set_script_translations(
			'fe-search-ai-admin-sync',
			'fe-search-ai',
			plugin_dir_path( FE_SEARCH_AI_PLUGIN_FILE ) . 'languages'
		);

		wp_localize_script(
			'fe-search-ai-admin-sync',
			'fe_search_ai_sync_obj',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'fe_search_ai_ajax_nonce' ),
				'i18n'     => [
					'processing_posts'   => __( 'Processing posts…', 'fe-search-ai' ),
					'preparing_sync'     => __( 'Preparing for synchronization…', 'fe-search-ai' ),
					'no_posts_to_sync'   => __( 'There are no posts to sync.', 'fe-search-ai' ),
					'error'              => __( 'Error:', 'fe-search-ai' ),
					'sync_complete'      => __( 'Synchronization complete!', 'fe-search-ai' ),
					'items'              => __( 'items', 'fe-search-ai' ),
					'batch_error'        => __( 'Error: A problem occurred while processing batch', 'fe-search-ai' ),
					'confirm_rebuild'    => __( 'This will rebuild the index from scratch and may take some time. Do you want to continue?', 'fe-search-ai' ),
					'confirm_smart_sync' => __( 'This will sync only new/updated/deleted content. Do you want to continue?', 'fe-search-ai' ),
				],
			]
		);
	}

	/**
	 * Add a dedicated menu to the admin panel.
	 */
	public function add_admin_menu() {
		$parent_slug = 'fe-search-ai';
		add_menu_page(
			'FE Search AI',
			'FE Search AI',
			'manage_options',
			$parent_slug,
			[ FE_Search_AI_Settings::class, 'render_page' ],
			'dashicons-search',
			80
		);
		add_submenu_page(
			$parent_slug,
			__( 'Settings', 'fe-search-ai' ),
			__( 'Settings', 'fe-search-ai' ),
			'manage_options',
			$parent_slug,
			[ FE_Search_AI_Settings::class, 'render_page' ]
		);
	}

	/**
	 * Ensures Settings API notices are rendered only in our custom container
	 * on the FE Search AI settings page.
	 *
	 * WordPress hooks settings_errors() into admin_notices globally, which
	 * causes the "Settings saved" notice to appear before our custom header
	 * layout. On the FE Search AI screen, we remove that global callback so
	 * that notices are only output where FE_AI_Search_Settings::render_page()
	 * explicitly calls settings_errors() inside the fe-search-ai-notices
	 * wrapper.
	 */
	public function maybe_reposition_settings_errors() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( 'toplevel_page_fe-search-ai' === $screen->id ) {
			remove_action( 'admin_notices', 'settings_errors' );
		}
	}
}
