<?php
/**
 * Manage the settings page (UI) of the plugin.
 *
 * Responsible for registering all setting sections
 * and fields using the WordPress Settings API and rendering their HTML.
 *
 * @package    fe-ai-search
 * @subpackage Admin
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The main settings page UI controller for the plugin.
 *
 * This class encapsulates all the logic for initializing and displaying the
 * various settings sections and fields, including API keys, synchronization options,
 * and display preferences.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Admin
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_Settings {

	private $options            = [];
	private $is_license_active  = false;
	private $license_alert_icon = '';

	public function __construct() {

		// Retrieve all configuration data
		$this->options = get_option( 'fe_ai_search_settings', [] );

		/**
		 * Check the status of the license (stored in its own option).
		 */
		$license_data = get_option( 'fe_ai_search_license', [] );
		$status       = $license_data['status'] ?? 'inactive';
		$products     = $license_data['data']['products'] ?? [];

		$this->is_license_active = ( 'active' === $status );

		if ( class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' ) && ! $this->is_license_active ) {
			$this->license_alert_icon = '<a href="' . admin_url( 'admin.php' )
			. '?page=fe-ai-search#tab_license">'
			. '<span class="dashicons dashicons-warning"></span></a>';
		}

		add_action( 'admin_init', [ $this, 'settings_init' ] );
	}

	/**
	 * Renders the HTML for the main settings page wrapper and tabs.
	 *
	 * This static method outputs the <form> tag and the tab navigation.
	 * It uses do_settings_sections() and do_settings_fields() to render the
	 * content for each tab, which is defined in the settings_init() method.
	 *
	 * @since 1.0.0
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<div id="plugin_header">
				<div id="plugin_header_upper">
					<h1>FE Search <span>AI</span></h1>
					<a href="https://www.firstelement.co.jp/" id="plugin_logo" target="_blank" title="<?php esc_attr_e( 'Go to the developer\'s website', 'fe-ai-search' ); ?>">
						<img src="<?php echo plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ); ?>/assets/images/logo-feas-white-shadow-s@2x-min.png" width="106" height="27">
					</a>
				</div>
				<div id="plugin_version">
					version <?php echo FE_AI_SEARCH_VERSION; ?>
				</div>
				<div id="plugin_support">
					<a href="https://fe-search.com/docs/ai/"
						target="_blank"
						title="<?php esc_attr_e( 'Go to the instruction manual', 'fe-ai-search' ); ?>">
						<?php esc_html_e( 'Docs', 'fe-ai-search' ); ?>
					</a>
					<a href="https://fe-search.com/support/forum/"
						target="_blank"
						title="<?php esc_attr_e( 'Go to a forum', 'fe-ai-search' ); ?>">
						<?php esc_html_e( 'Forums', 'fe-ai-search' ); ?>
					</a>
					<a href="https://github.com/firstelementjp/fe-ai-search"
						target="_blank"
						title="<?php esc_attr_e( 'Go to GitHub repository', 'fe-ai-search' ); ?>"
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
						title="<?php esc_attr_e( 'Go to a YouTube channel', 'fe-ai-search' ); ?>"
						class="icon icon_yt">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 32 32"
							width="20"
							height="20"
						>
							<path
								fill="currentColor"
								d="M29.41,9.26a3.5,3.5,0,0,0-2.47-2.47C24.76,6.2,16,6.2,16,6.2s-8.76,0-10.94.59A3.5,3.5,0,0,0,2.59,9.26,36.13,36.13,0,0,0,2,16a36.13,36.13,0,0,0,.59,6.74,3.5,3.5,0,0,0,2.47,2.47C7.24,25.8,16,25.8,16,25.8s8.76,0,10.94-.59a3.5,3.5,0,0,0,2.47-2.47A36.13,36.13,0,0,0,30,16,36.13,36.13,0,0,0,29.41,9.26ZM13.2,20.2V11.8L20.47,16Z"
							/>
						</svg>
					</a>
					<a href="https://x.com/feas_wp/"
						target="_blank"
						title="<?php esc_attr_e( 'Go to X', 'fe-ai-search' ); ?>"
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
						title="<?php esc_attr_e( 'Go to Facebook page', 'fe-ai-search' ); ?>"
						class="icon icon_fb">
					</a>
					<a href="https://fe-search.com/contact/"
						target="_blank"
						title="<?php esc_attr_e( 'Go to contact form', 'fe-ai-search' ); ?>"
						class="icon icon_mail">
					</a>
				</div>
			</div>

			<?php
			// Determine if the Pro license is active (same logic as in the constructor).
			$license_data = get_option( 'fe_ai_search_license', [] );
			$status       = $license_data['status'] ?? 'inactive';
			$products     = $license_data['data']['products'] ?? [];
			$is_pro       = ( 'active' === $status );
			?>
			<div class="nav-tab-wrapper">
				<a href="#tab_provider" class="nav-tab"><?php esc_html_e( 'Providers', 'fe-ai-search' ); ?></a>
				<?php if ( $is_pro ) : ?>
					<a href="#tab_models" class="nav-tab"><?php esc_html_e( 'Models', 'fe-ai-search' ); ?></a>
				<?php endif; ?>
				<a href="#tab_sync" class="nav-tab"><?php esc_html_e( 'Sync', 'fe-ai-search' ); ?></a>
				<a href="#tab_prompt" class="nav-tab"><?php esc_html_e( 'Prompts', 'fe-ai-search' ); ?></a>
				<a href="#tab_display" class="nav-tab"><?php esc_html_e( 'Display', 'fe-ai-search' ); ?></a>
				<?php if ( $is_pro ) : ?>
					<a href="#tab_security" class="nav-tab"><?php esc_html_e( 'Security', 'fe-ai-search' ); ?></a>
				<?php endif; ?>
				<a href="#tab_advanced" class="nav-tab"><?php esc_html_e( 'Advanced settings', 'fe-ai-search' ); ?></a>
				<?php
				/**
				 * Fires within the settings page's tab wrapper to add custom navigation tabs.
				 *
				 * This action is now primarily used for the License tab (and other
				 * non-Pro add-ons), as Pro-specific tabs are pre-defined above and
				 * only shown when the license is active.
				 *
				 * @since 1.0.0
				 */
				do_action( 'fe_ai_search_settings_tabs' );
				?>
			</div>

			<?php
			// Show Settings API errors for the Free plugin settings group.
			// Pro-specific errors are rendered inside their own tabs (e.g., Security).
			// settings_errors();
			?>

			<form action="options.php" method="post">
				<?php
				// Use the correct settings group name defined in settings_init()
				settings_fields( 'fe-ai-search-settings-group' );
				?>

				<div id="tab_provider" class="tab-content">
					<details>
						<summary>
							<?php esc_html_e( 'API usage limits and costs', 'fe-ai-search' ); ?>
						</summary>
						<div>
							<p>
								<?php esc_html_e( 'Generate an API key in the dashboard of each AI provider you plan to use, and enter it in the fields below. Using these APIs incurs usage-based charges. Before enabling the chat, please review each provider\'s pricing carefully. To help prevent unexpected token usage due to misconfiguration or abuse, this plugin applies built-in rate limits.', 'fe-ai-search' ); ?>
							</p>
							<p class="fe-ai-subheading"><?php esc_html_e( 'Default API usage limits', 'fe-ai-search' ); ?></p>
							<ul>
								<li><?php esc_html_e( 'Per IP address: 50 requests per hour', 'fe-ai-search' ); ?></li>
								<li><?php esc_html_e( 'Per site (total): 1,000 requests per day', 'fe-ai-search' ); ?></li>
							</ul>
							<p>
								<?php
									/* translators: 1: opening code tag, 2: closing code tag, 3: opening link tag, 4: closing link tag */
									printf(
										esc_html__( 'You can change these limits from your theme or another plugin using the %1$sfe_ai_search_rate_limit_settings%2$s filter hook. For full sample code, please refer to the %3$sdocumentation%4$s.', 'fe-ai-search' ),
										'<code>',
										'</code>',
										'<a href="https://fe-search.com/ai/manual/" target="_blank" rel="noopener noreferrer">',
										'</a>'
									);
								?>
							</p>
						</div>
					</details>
					<?php do_settings_sections( 'fe_ai_search_chat_provider_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_chat_provider_section' ); ?>
					</table>

					<?php do_settings_sections( 'fe_ai_search_embedding_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_embedding_section' ); ?>
					</table>

					<?php do_settings_sections( 'fe_ai_search_api_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_api_section' ); ?>
					</table>

					<?php do_action( 'fe_ai_search_after_api_settings_fields' ); ?>
				</div>

				<div id="tab_sync" class="tab-content">
					<?php do_settings_sections( 'fe_ai_search_sync_options_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_sync_options_section' ); ?>
					</table>

					<?php do_settings_sections( 'fe_ai_search_sync_ui_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_sync_ui_section' ); ?>
					</table>

					<?php do_settings_sections( 'fe_ai_search_sync_advanced_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_sync_advanced_section' ); ?>
					</table>
					<?php // Pro add-on: tuning (Custom Stop Words) now lives at the end of the Sync tab. ?>
					<?php do_settings_sections( 'fe_ai_search_tuning_section_pro' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_tuning_section_pro' ); ?>
					</table>
				</div>

				<div id="tab_display" class="tab-content">
					<?php do_settings_sections( 'fe_ai_search_display_floating_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_display_floating_section' ); ?>
					</table>

					<?php do_settings_sections( 'fe_ai_search_display_embed_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_display_embed_section' ); ?>
					</table>

					<?php do_settings_sections( 'fe_ai_search_display_fullscreen_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_display_fullscreen_section' ); ?>
					</table>

					<?php do_settings_sections( 'fe_ai_search_display_appearance_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_display_appearance_section' ); ?>
					</table>
					<?php // Pro add-on: Privacy opt-in (User Consent) is shown below the legal links. ?>
					<?php do_settings_sections( 'fe_ai_search_privacy_section_pro' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_privacy_section_pro' ); ?>
					</table>
				</div>

				<div id="tab_prompt" class="tab-content">
					<?php do_settings_sections( 'fe_ai_search_prompt_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_prompt_section' ); ?>
					</table>
					<?php do_action( 'fe_ai_search_after_prompt_settings_fields' ); ?>
				</div>

				<div id="tab_advanced" class="tab-content">
					<?php do_settings_sections( 'fe_ai_search_advanced_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_advanced_section' ); ?>
					</table>
					<?php // Data management (previously in its own "Data" tab). ?>
					<?php do_settings_sections( 'fe_ai_search_data_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_data_section' ); ?>
					</table>
					<?php // Pro add-on: external API token section only. ?>
					<?php do_settings_sections( 'fe_ai_search_api_token_section_pro' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_api_token_section_pro' ); ?>
					</table>
				</div>

				<?php
				/**
				 * Fires within the main settings form to add custom tab content panels.
				 *
				 * This action is intended to be used in conjunction with the
				 * 'fe_ai_search_settings_tabs' action. It allows other plugins to render the
				 * content (the <div id="tab_...">) for the custom tabs they have added.
				 *
				 * @since 1.0.0
				 */
				do_action( 'fe_ai_search_settings_tabs_content' );
				?>

				<?php submit_button( __( 'Save Settings', 'fe-ai-search' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Initialize the Settings API: Register the master setting and define sections/fields.
	 */
	public function settings_init() {
		$settings_group = 'fe-ai-search-settings-group';
		$page_slug      = 'fe-ai-search';

		register_setting(
			$settings_group,
			'fe_ai_search_settings',
			[ 'sanitize_callback' => [ $this, 'sanitize_main_settings' ] ]
		);

		// ===================================================
		// Define all sections and their corresponding fields.
		// ===================================================

		// ------------------
		// Provider Tab
		// ------------------
		// Chat Model Section
		add_settings_section( 'fe_ai_search_chat_provider_section', __( 'Chat Model', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_chat_provider', __( 'Chat AI', 'fe-ai-search' ), [ $this, 'chat_provider_field_html' ], $page_slug, 'fe_ai_search_chat_provider_section' );

		// Embedding Model Section
		add_settings_section( 'fe_ai_search_embedding_section', __( 'Vectorization Model', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_embedding_provider', __( 'Vectorization AI', 'fe-ai-search' ), [ $this, 'embedding_provider_field_html' ], $page_slug, 'fe_ai_search_embedding_section' );

		// API Keys Section
		add_settings_section( 'fe_ai_search_api_section', __( 'API Keys', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_openai_api_key', 'OpenAI (GPT)', [ $this, 'openai_api_key_field_html' ], $page_slug, 'fe_ai_search_api_section' );
		add_settings_field( 'fe_ai_search_google_api_key', 'Google (Gemini)', [ $this, 'google_api_key_field_html' ], $page_slug, 'fe_ai_search_api_section' );
		add_settings_field( 'fe_ai_search_anthropic_api_key', 'Anthropic (Claude)', [ $this, 'anthropic_api_key_field_html' ], $page_slug, 'fe_ai_search_api_section' );

		// ------------------
		// Sync Tab
		// ------------------
		// Sync Controls Section
		add_settings_section( 'fe_ai_search_sync_ui_section', __( 'Synchronization', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_sync_ui', __( 'Sync Controls', 'fe-ai-search' ), [ $this, 'sync_ui_field_html' ], $page_slug, 'fe_ai_search_sync_ui_section' );

		// Sync Targets Section
		add_settings_section( 'fe_ai_search_sync_options_section', __( 'Content to Sync', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_sync_options', __( 'Sync Targets', 'fe-ai-search' ), [ $this, 'sync_options_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );
		add_settings_field( 'fe_ai_search_include_post_ids', __( 'Only Sync Specific Posts', 'fe-ai-search' ), [ $this, 'include_post_ids_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );
		add_settings_field( 'fe_ai_search_exclude_post_ids', __( 'Exclude Specific Posts', 'fe-ai-search' ), [ $this, 'exclude_post_ids_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );
		add_settings_field( 'fe_ai_search_sync_limit', __( 'Sync Limit', 'fe-ai-search' ), [ $this, 'sync_limit_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );
		add_settings_field( 'fe_ai_search_batch_size', __( 'Batch Size', 'fe-ai-search' ), [ $this, 'batch_size_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );

		// Advanced Sync Section
		add_settings_section( 'fe_ai_search_sync_advanced_section', __( 'Advanced Sync Settings', 'fe-ai-search' ), null, $page_slug );

		// ------------------
		// Display Tab
		// ------------------
		// Appearance Section
		add_settings_section( 'fe_ai_search_display_appearance_section', __( 'Chat UI Appearance', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_display_chat_text', __( 'Chat Text & Colors', 'fe-ai-search' ), [ $this, 'display_text_color_field_html' ], $page_slug, 'fe_ai_search_display_appearance_section' );
		add_settings_field( 'fe_ai_search_display_interaction', __( 'Interaction', 'fe-ai-search' ), [ $this, 'display_interaction_field_html' ], $page_slug, 'fe_ai_search_display_appearance_section' );
		add_settings_field( 'fe_ai_search_display_links', __( 'Legal Links', 'fe-ai-search' ), [ $this, 'display_links_field_html' ], $page_slug, 'fe_ai_search_display_appearance_section' );

		// Floating Mode Section
		add_settings_section( 'fe_ai_search_display_floating_section', __( 'Floating Mode Settings', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_display_floating', __( 'Display Rules', 'fe-ai-search' ), [ $this, 'display_floating_field_html' ], $page_slug, 'fe_ai_search_display_floating_section' );

		// Embed Mode Section
		add_settings_section( 'fe_ai_search_display_embed_section', __( 'Embed Mode', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_display_embed', __( 'Shortcode', 'fe-ai-search' ), [ $this, 'display_embed_field_html' ], $page_slug, 'fe_ai_search_display_embed_section' );

		// Full Screen Mode Section
		add_settings_section( 'fe_ai_search_display_fullscreen_section', __( 'Fullscreen Mode Settings', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_display_fullscreen', __( 'Fullscreen Page', 'fe-ai-search' ), [ $this, 'display_fullscreen_field_html' ], $page_slug, 'fe_ai_search_display_fullscreen_section' );

		// ------------------
		// Prompt Tab
		// ------------------
		add_settings_section( 'fe_ai_search_prompt_section', __( 'Prompt Settings', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_site_name', __( 'AI Site Name', 'fe-ai-search' ), [ $this, 'site_name_field_html' ], $page_slug, 'fe_ai_search_prompt_section' );
		add_settings_field( 'fe_ai_search_site_purpose', __( 'AI Site Purpose', 'fe-ai-search' ), [ $this, 'site_purpose_field_html' ], $page_slug, 'fe_ai_search_prompt_section' );
		add_settings_field( 'fe_ai_search_system_prompt', __( 'Base System Prompt', 'fe-ai-search' ), [ $this, 'system_prompt_field_html' ], $page_slug, 'fe_ai_search_prompt_section' );

		// ------------------
		// Data Tab
		// ------------------
		add_settings_section( 'fe_ai_search_data_section', __( 'Data Management', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_delete_vectors_ui', __( 'Delete Synced Data', 'fe-ai-search' ), [ $this, 'delete_vectors_ui_field_html' ], $page_slug, 'fe_ai_search_data_section' );
		add_settings_field( 'fe_ai_search_delete_on_uninstall', __( 'Delete Data on Uninstall', 'fe-ai-search' ), [ $this, 'delete_on_uninstall_field_html' ], $page_slug, 'fe_ai_search_data_section' );

		// ------------------
		// Advanced Tab
		// ------------------
		add_settings_section( 'fe_ai_search_advanced_section', __( 'Advanced Settings', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_display_advanced', __( 'Assets Loading', 'fe-ai-search' ), [ $this, 'display_advanced_field_html' ], $page_slug, 'fe_ai_search_advanced_section' );
		add_settings_field( 'fe_ai_search_debug_mode_enabled', __( 'Debug Mode', 'fe-ai-search' ), [ $this, 'debug_mode_field_html' ], $page_slug, 'fe_ai_search_advanced_section' );
	}

	/**
	 * Determine whether the Pro version add-on is enabled
	 *
	 * @return bool
	 */
	public function chat_provider_field_html() {
		$provider = $this->options['provider']['chat'] ?? 'openai';

		$providers = [
			'openai'    => __( 'OpenAI（GPT）', 'fe-ai-search' ),
			'google'    => __( 'Google (Gemini)', 'fe-ai-search' ),
			'anthropic' => __( 'Anthropic (Claude)', 'fe-ai-search' ),
		];

		/**
		 * Filters the array of available AI chat providers.
		 *
		 * This hook allows other plugins or themes to add, remove, or modify
		 * the AI models available for selection in the settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array $providers An associative array of AI providers,
		 * where the key is the provider slug (e.g., 'openai')
		 * and the value is the display name (e.g., 'OpenAI (GPT-4o)').
		 */
		$providers = apply_filters( 'fe_ai_search_chat_providers', $providers );

		?>
		<select name="fe_ai_search_settings[provider][chat]">
			<?php foreach ( $providers as $key => $name ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $provider, $key ); ?>>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the AI that generates answers for the user.', 'fe-ai-search' ); ?>
		</p>
		<?php
	}

	public function embedding_provider_field_html() {
		$provider = $this->options['provider']['embedding'] ?? 'openai';

		$providers = [
			'openai' => 'OpenAI (text-embedding-3)',
			'google' => 'Google (text-embedding-004)',
		];

		/**
		 * Filters the array of available AI embedding providers.
		 *
		 * This hook allows other plugins or themes to add, remove, or modify
		 * the AI models available for selection for the vectorization process.
		 *
		 * @since 1.0.0
		 *
		 * @param array $providers An associative array of AI embedding providers,
		 * where the key is the provider slug (e.g., 'google')
		 * and the value is the display name (e.g., 'Google (text-embedding-004)').
		 */
		$providers = apply_filters( 'fe_ai_search_embedding_providers', $providers );

		?>
		<select name="fe_ai_search_settings[provider][embedding]">
			<?php foreach ( $providers as $key => $name ) : ?>
				<option
					value="<?php echo esc_attr( $key ); ?>" <?php selected( $provider, $key ); ?>
					<?php
					if ( 'anthropic' === $key ) {
						echo 'disabled="disabled"';}
					?>
				>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php
			esc_html_e( 'Select an AI that will vectorize (convert) your site content into a format that AI can understand.', 'fe-ai-search' );
			?>
		</p>
		<?php
	}

	/**
	 * Renders the container and calls individual methods for each API key field.
	 * This acts as the main callback for the 'API Keys' field.
	 *
	 * @since 1.0.0
	 */
	public function api_keys_field_html() {
		// This method now simply calls the specific rendering methods for each provider.
		?>
		<div class="api-keys-wrapper">
			<?php $this->openai_api_key_field_html(); ?>
			<?php $this->google_api_key_field_html(); ?>
			<?php $this->anthropic_api_key_field_html(); ?>
			<?php
			// Allow Pro version to add its API key fields here
			do_action( 'fe_ai_search_after_api_key_fields', $this );
			?>
		</div>
		<?php
	}

	public function openai_api_key_field_html() {
		$api_key = $this->options['provider']['openai_key'] ?? '';

		$model_to_display = 'gpt-4o-mini';
		if ( $this->is_license_active ) {
			$pro_options      = get_option( 'fe_ai_search_pro_settings', $model_to_display );
			$model_to_display = $pro_options['model']['openai'] ?? $model_to_display;
		}
		?>
		<input
			type="password"
			id="fe_ai_search_openai_api_key"
			name="fe_ai_search_settings[provider][openai_key]"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
		>
		<button type="button" class="button button-secondary fe-ai-search-test-api" data-provider="openai">
			<?php esc_html_e( 'Connection Test', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner"></span>
		<span class="fe-ai-search-api-status"></span>
		<p class="description">
			<?php esc_html_e( 'Enter your OpenAI API key.', 'fe-ai-search' ); ?>
		</p>

		<div>
			<p>
				<strong><?php esc_html_e( 'Model', 'fe-ai-search' ); ?>:</strong> <?php echo esc_html( $model_to_display ); ?>
				<?php if ( ! class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) ) : ?>
					<div class="description">
						<?php
						printf(
							/* translators: %s: Link to the Pro version. */
							wp_kses_post( __( 'Available in the %s version.', 'fe-ai-search' ) ),
							sprintf(
								'<a href="%s" class="%s">%s</a>',
								'#tab_license',
								'fe-ai-search-change-model-link',
								esc_html__( 'Pro', 'fe-ai-search' )
							)
						)
						?>
					</div>
				<?php else : ?>
					<div>
						<?php echo $this->license_alert_icon; ?>
						<a href="#tab_models" class="fe-ai-search-change-model-link">
							<?php esc_html_e( 'Change Model', 'fe-ai-search' ); ?> &raquo;
						</a>
					</div>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public function google_api_key_field_html() {
		$api_key = $this->options['provider']['google_key'] ?? '';

		$model_to_display = 'gemini-2.5-flash-lite';
		if ( $this->is_license_active ) {
			$pro_options      = get_option( 'fe_ai_search_pro_settings', $model_to_display );
			$model_to_display = $pro_options['model']['google'] ?? $model_to_display;
		}
		?>
		<input
			type="password"
			id="fe_ai_search_google_api_key"
			name="fe_ai_search_settings[provider][google_key]"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
		>
		<button type="button" class="button button-secondary fe-ai-search-test-api" data-provider="google">
			<?php esc_html_e( 'Connection Test', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner"></span>
		<span class="fe-ai-search-api-status"></span>
		<p class="description">
			<?php esc_html_e( 'Enter your Google Cloud API key.', 'fe-ai-search' ); ?>
		</p>

		<div>
			<p>
				<strong><?php esc_html_e( 'Model', 'fe-ai-search' ); ?>:</strong> <?php echo esc_html( $model_to_display ); ?>
				<?php if ( ! class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) ) : ?>
					<div class="description">
						<?php
						printf(
							/* translators: %s: Link to the Pro version. */
							wp_kses_post( __( 'Available in the %s version.', 'fe-ai-search' ) ),
							sprintf(
								'<a href="%s" class="%s">%s</a>',
								'#tab_license',
								'fe-ai-search-change-model-link',
								esc_html__( 'Pro', 'fe-ai-search' )
							)
						)
						?>
					</div>
				<?php else : ?>
					<div>
						<?php echo $this->license_alert_icon; ?>
						<a href="#tab_models" class="fe-ai-search-change-model-link">
							<?php esc_html_e( 'Change Model', 'fe-ai-search' ); ?> &raquo;
						</a>
					</div>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public function anthropic_api_key_field_html() {
		$api_key = $this->options['provider']['anthropic_key'] ?? '';

		$model_to_display = 'claude-haiku-4-5-20251001';
		if ( $this->is_license_active ) {
			$pro_options      = get_option( 'fe_ai_search_pro_settings', $model_to_display );
			$model_to_display = $pro_options['model']['anthropic'] ?? $model_to_display;
		}
		?>
		<input
			type="password"
			id="fe_ai_search_anthropic_api_key"
			name="fe_ai_search_settings[provider][anthropic_key]"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
		>
		<button type="button" class="button button-secondary fe-ai-search-test-api" data-provider="anthropic">
			<?php esc_html_e( 'Connection Test', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner"></span>
		<span class="fe-ai-search-api-status"></span>
		<p class="description">
			<?php esc_html_e( 'Enter your Anthropic (Claude) API key.', 'fe-ai-search' ); ?>
		</p>

		<div>
			<p>
				<strong><?php esc_html_e( 'Model', 'fe-ai-search' ); ?>:</strong> <?php echo esc_html( $model_to_display ); ?>
				<?php if ( ! class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) ) : ?>
					<div class="description">
						<?php
						printf(
							/* translators: %s: Link to the Pro version. */
							wp_kses_post( __( 'Available in the %s version.', 'fe-ai-search' ) ),
							sprintf(
								'<a href="%s" class="%s">%s</a>',
								'#tab_license',
								'fe-ai-search-change-model-link',
								esc_html__( 'Pro', 'fe-ai-search' )
							)
						)
						?>
					</div>
				<?php else : ?>
					<div>
						<?php echo $this->license_alert_icon; ?>
						<a href="#tab_models" class="fe-ai-search-change-model-link">
							<?php esc_html_e( 'Change Model', 'fe-ai-search' ); ?> &raquo;
						</a>
					</div>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}


	public function sync_options_field_html() {
		// New sync schema: per-post-type options are stored under sync['targets'][post_type].
		$sync_targets = $this->options['sync']['targets'] ?? [];

		$post_types          = get_post_types( [ 'public' => true ], 'objects' );
		$excluded_post_types = [ 'attachment' ];

		$string = __( 'Select the post types you want to include in your AI search and the metadata you want to include in chunks for each post type.', 'fe-ai-search' );
		echo '<p class="description">' . wp_kses_post( $string ) . '</p>';
		echo '<div id="fe_ai_search_sync_options_accordion" class="fe-ai-search-accordion-wrapper">';

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $excluded_post_types, true ) ) {
				continue;
			}

			// Get saved options for this post type from sync['targets'][post_type].
			$pt_options      = $sync_targets[ $post_type->name ] ?? [];
			$is_enabled      = $pt_options['enabled'] ?? in_array( $post_type->name, [ 'post', 'page' ], true );
			$include_title   = $pt_options['include_title'] ?? true;
			$include_content = $pt_options['include_content'] ?? true;
			$include_date    = $pt_options['include_date'] ?? true;
			$include_author  = $pt_options['include_author'] ?? true;
			?>
			<div class="post-type-accordion-item">
				<h4 class="accordion-title">
					<label>
						<input
							type="checkbox"
							name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][enabled]"
							value="1"
							<?php checked( $is_enabled ); ?>
						>
						<?php echo esc_html( $post_type->label ); ?> (<?php echo esc_html( $post_type->name ); ?>)
					</label>
				</h4>
				<div class="accordion-content">
					<div class="accordion-inner">
						<fieldset>
							<label>
								<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][include_title]" value="1" <?php checked( $include_title ); ?>>
								<?php esc_html_e( 'Include Post Title', 'fe-ai-search' ); ?>
							</label>
							<label>
								<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][include_content]" value="1" <?php checked( $include_content ); ?>>
								<?php esc_html_e( 'Include Post Content', 'fe-ai-search' ); ?>
							</label>
							<label>
								<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][include_date]" value="1" <?php checked( $include_date ); ?>>
								<?php esc_html_e( 'Include Post Date', 'fe-ai-search' ); ?>
							</label>
							<label>
								<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][include_author]" value="1" <?php checked( $include_author ); ?>>
								<?php esc_html_e( 'Include Post Author (nickname)', 'fe-ai-search' ); ?>
							</label>

							<?php
							$taxonomies = get_object_taxonomies( $post_type->name, 'objects' );
							if ( ! empty( $taxonomies ) ) {
								foreach ( $taxonomies as $tax ) {
									if ( ! $tax->public ) {
										continue;
									}
									$is_checked = ! empty( $pt_options['taxonomies'] ) && in_array( $tax->name, $pt_options['taxonomies'], true );
									?>
									<label>
										<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][taxonomies][]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( $is_checked ); ?>>
										<?php printf( esc_html__( 'Include %s', 'fe-ai-search' ), esc_html( $tax->label ) ); ?>
									</label>
									<?php
								}
							}
							?>

							<div>
								<?php
								$enable_custom_fields  = $pt_options['enable_custom_fields'] ?? false;
								$custom_fields_value   = $pt_options['custom_fields'] ?? '';
								$custom_field_input_id = 'fe_ai_search_custom_fields_' . esc_attr( $post_type->name );
								?>

								<label>
									<input
										type="checkbox"
										name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][enable_custom_fields]"
										value="1"
										class="fe-ai-search-cf-toggle"
										data-target-input-id="<?php echo $custom_field_input_id; ?>"
										<?php checked( $enable_custom_fields ); ?>
										<?php disabled( ! $this->is_license_active ); ?>
									>
									<?php echo $this->license_alert_icon; ?>
									<?php esc_html_e( 'Include Custom Fields', 'fe-ai-search' ); ?>
									<?php if ( ! class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) ) : ?>
										<span class="description">(
										<?php
										printf(
											/* translators: %s: Link to the Pro version. */
											wp_kses_post( __( 'Available in the %s version.', 'fe-ai-search' ) ),
											sprintf(
												'<a href="%s" class="%s">%s</a>',
												'#tab_license',
												'fe-ai-search-change-model-link',
												esc_html__( 'Pro', 'fe-ai-search' )
											)
										);
										?>
										)</span>
									<?php endif; ?>
								</label>

								<div class="custom-field-input-wrapper" style="margin-left: 20px;">
									<input
										type="text"
										id="<?php echo $custom_field_input_id; ?>"
										name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][custom_fields]"
										value="<?php echo esc_attr( $custom_fields_value ); ?>"
										class="regular-text"
										placeholder="field_name_1, field_name_2"
										<?php disabled( ! $this->is_license_active ); ?>
									>
									<p class="description">
										<?php esc_html_e( 'Enter the keys of the custom fields you want to include, separated by commas.', 'fe-ai-search' ); ?>
									</p>
								</div>
							</div>
						</fieldset>
					</div>
				</div>
			</div>
			<?php
		}
		echo '</div>';
	}


	/**
	 * Renders the HTML for the synchronization UI.
	 *
	 * This includes the status of the Japanese tokenizer (if applicable),
	 * the last sync time, and the buttons to trigger a sync.
	 *
	 * @since 1.0.0
	 */
	public function sync_ui_field_html() {
		global $wpdb;
		$vectors_table = $wpdb->prefix . 'fe_ai_search_vectors';

		// Check if the table exists *before* querying it to prevent errors.
		$table_exists       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vectors_table ) ) === $vectors_table;
		$indexed_post_count = 0;
		if ( $table_exists ) {
			$indexed_post_count = $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM `{$vectors_table}`" );
		}

		// Get sync status from the dedicated runtime state option so that it is
		// not affected by settings sanitization.
		$sync_state  = get_option( 'fe_ai_search_sync_state', [] );
		$sync_status = $sync_state['status'] ?? [];
		$last_sync   = $sync_status['last_sync_timestamp'] ?? 0;
		?>
		<div>
			<p class="sync-status">
				<?php
				if ( $last_sync ) {
					printf(
						'<strong>%s:</strong> %s',
						esc_html__( 'Last Sync', 'fe-ai-search' ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) )
					);
				}
				printf(
					'&nbsp;&nbsp;<strong>%s:</strong> %s %s',
					esc_html__( 'Indexed Posts', 'fe-ai-search' ),
					esc_html( $indexed_post_count ),
					esc_html__( 'posts', 'fe-ai-search' )
				);
				?>
			</p>

			<div id="fe_ai_search_sync_wrapper">
				<?php
				/**
				 * Filters the array of status messages to display on the Sync tab.
				 *
				 * This allows the Sync_Handler (for MeCab) or add-ons (for other languages)
				 * to add their own tokenizer status UI.
				 *
				 * @since 1.0.0
				 * @param array $statuses An array of HTML strings, each representing a status line.
				 */
				$tokenizer_statuses = apply_filters( 'fe_ai_search_tokenizer_status', [] );

				if ( ! empty( $tokenizer_statuses ) ) :
					?>
					<div id="fe_ai_search_tokenizer_status">
						<?php
						foreach ( $tokenizer_statuses as $status_html ) {
							echo '<p>' . wp_kses_post( $status_html ) . '</p>';
						}
						?>
					</div>
				<?php endif; ?>

				<div class="fe-ai-search-wrapper">
					<button id="fe_ai_search_smart_sync" class="button button-primary">
						<?php esc_html_e( 'Sync Changes (Recommended)', 'fe-ai-search' ); ?>
					</button>
					<button id="fe_ai_search_start_sync" class="button button-secondary">
						<?php esc_html_e( 'Rebuild Index', 'fe-ai-search' ); ?>
					</button>
				</div>

				<div id="fe_ai_search_progress_container">
					<div id="fe_ai_search_progress_bar_wrapper">
						<div id="fe_ai_search_progress_bar">0%</div>
					</div>
					<div id="fe_ai_search_sync_status">
						<span class="dashicons dashicons-update-alt spin"></span>
						<span class="status-text"></span>
					</div>
				</div>
			</div>

			<p class="description">
				<?php esc_html_e( '"Sync Changes" processes only new, updated, or deleted content. "Rebuild Index" syncs everything from scratch.', 'fe-ai-search' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the UI for deleting all synced vector and keyword index data.
	 *
	 * This displays a button that triggers an AJAX-based delete process and
	 * a status area for feedback messages.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function delete_vectors_ui_field_html() {
		?>
		<p class="description">
			<?php esc_html_e( 'This will delete all synced vector data and keyword indexes. AI search will no longer work until you sync again.', 'fe-ai-search' ); ?>
		</p>
		<button type="button" id="fe_ai_search_delete_vectors_button" class="button button-secondary">
			<?php esc_html_e( 'Delete all synced data', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner"></span>
		<p id="fe_ai_search_delete_status"></p>
		<?php
	}

	/**
	 * Renders the settings for the floating chat widget display rules.
	 *
	 * Controls login status visibility, device targeting, and per-template
	 * display conditions including include/exclude post ID rules.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_floating_field_html() {
		$floating_options = $this->options['display']['floating'] ?? [];

		$defaults         = [
			'enable_floating_mode'    => true,
			'display_on_pc'           => true,
			'display_on_mobile'       => true,
			'show_to_logged_in_users' => true,
			'show_to_guests'          => true,
			'fullscreen_page_id'      => 0,
			'display_rules'           => [
				'show_on_front_page' => true,
				'show_on_archives'   => true,
				'show_on_search'     => true,
				'show_on_404'        => true,
				'show_on_singular'   => true,
				'include_ids'        => '',
				'exclude_ids'        => '',
			],
		];
		$floating_options = wp_parse_args( $floating_options, $defaults );
		?>
		<fieldset id="fe_ai_search_settings_display_floating">
			<div id="fe_ai_search_floating_display_accordion" class="fe-ai-search-accordion-wrapper">
				<div class="post-type-accordion-item">
					<h4 class="accordion-title">
						<label>
							<input
								type="checkbox"
								name="fe_ai_search_settings[display][floating][enable_floating_mode]"
								value="1" <?php checked( $floating_options['enable_floating_mode'] ); ?>
							>
							<strong><?php esc_html_e( 'Enable floating chat', 'fe-ai-search' ); ?></strong>
						</label>
					</h4>

					<div class="accordion-content">
						<div class="accordion-inner">

							<div class="fe-ai-search-floating-login-status">
								<div class="option-header"><?php esc_html_e( 'Login status', 'fe-ai-search' ); ?>:</div>
								<p>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][show_to_logged_in_users]"
											value="1" <?php checked( ! empty( $floating_options['show_to_logged_in_users'] ) ); ?>
										>
										<?php esc_html_e( 'Show to logged-in users', 'fe-ai-search' ); ?>
									</label>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][show_to_guests]"
											value="1" <?php checked( ! empty( $floating_options['show_to_guests'] ) ); ?>
										>
										<?php esc_html_e( 'Show to non-logged-in users', 'fe-ai-search' ); ?>
									</label>
								</p>
							</div>

							<div class="fe-ai-search-floating-devices">
								<div class="option-header"><?php esc_html_e( 'Display device', 'fe-ai-search' ); ?>:</div>
								<p>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][display_on_pc]"
											value="1" <?php checked( $floating_options['display_on_pc'] ); ?>
										> <?php esc_html_e( 'Show on PC', 'fe-ai-search' ); ?>
									</label>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][display_on_mobile]"
											value="1" <?php checked( $floating_options['display_on_mobile'] ); ?>
										> <?php esc_html_e( 'Show on Mobile', 'fe-ai-search' ); ?>
									</label>
								</p>
							</div>

							<div id="fe_ai_search_condition_for_displaying_the_chat">
								<div class="option-header">
									<?php esc_html_e( 'Conditions for Displaying the Chat', 'fe-ai-search' ); ?>:
								</div>
								<p>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][display_rules][show_on_front_page]"
											value="1" <?php checked( $floating_options['display_rules']['show_on_front_page'] ); ?>
										>
										<?php esc_html_e( 'Home', 'fe-ai-search' ); ?>
									</label>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][display_rules][show_on_archives]"
											value="1" <?php checked( $floating_options['display_rules']['show_on_archives'] ); ?>
										>
										<?php esc_html_e( 'Archive', 'fe-ai-search' ); ?>
									</label>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][display_rules][show_on_search]"
											value="1" <?php checked( $floating_options['display_rules']['show_on_search'] ); ?>
										>
										<?php esc_html_e( 'Search result page', 'fe-ai-search' ); ?>
									</label>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][display_rules][show_on_404]"
											value="1" <?php checked( ! empty( $floating_options['display_rules']['show_on_404'] ) ); ?>
										>
										<?php esc_html_e( '404 page', 'fe-ai-search' ); ?>
									</label>
									<label>
										<input
											type="checkbox"
											name="fe_ai_search_settings[display][floating][display_rules][show_on_singular]"
											value="1" <?php checked( ! empty( $floating_options['display_rules']['show_on_singular'] ) ); ?>
										>
										<?php esc_html_e( 'Single pages', 'fe-ai-search' ); ?>
									</label>
								</p>

								<div class="option-header">
									<?php esc_html_e( 'Overwrite rules by individual ID', 'fe-ai-search' ); ?>:
								</div>
								<p>
									<label for="fe_ai_search_include_ids">
										<?php esc_html_e( 'Display only with these post IDs', 'fe-ai-search' ); ?>:
									</label>
									<input
										type="text"
										id="fe_ai_search_include_ids"
										name="fe_ai_search_settings[display][floating][display_rules][include_ids]"
										value="<?php echo esc_attr( $floating_options['display_rules']['include_ids'] ); ?>"
										class="regular-text"
										placeholder="<?php esc_attr_e( 'e.g.', 'fe-ai-search' ); ?> 10, 25, 103"
									>
									<span class="description">
										<?php esc_html_e( 'If you enter the ID here, other display rules will be ignored.', 'fe-ai-search' ); ?>
									</span>
								</p>

								<p>
									<label for="fe_ai_search_exclude_ids">
										<?php esc_html_e( 'Do not display with these post IDs.', 'fe-ai-search' ); ?>:
									</label>
									<input
										type="text"
										id="fe_ai_search_exclude_ids"
										name="fe_ai_search_settings[display][floating][display_rules][exclude_ids]"
										value="<?php echo esc_attr( $floating_options['display_rules']['exclude_ids'] ); ?>"
										class="regular-text"
										placeholder="<?php esc_attr_e( 'e.g.', 'fe-ai-search' ); ?> 15, 30"
									>
								</p>
							</div><!-- #fe_ai_search_condition_for_displaying_the_chat -->

						</div><!-- .accordion-inner -->
					</div><!-- .accordion-content -->
				</div><!-- .post-type-accordion-item -->
			</div><!-- #fe-ai-search-floating-display-accordion -->
		</fieldset>
		<?php
	}

	/**
	 * Renders the UI for the embed mode shortcode.
	 *
	 * Outputs a simple description and the shortcode that can be pasted into
	 * a fixed page or post to embed the chat UI.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_embed_field_html() {
		?>
		<p class="description">
			<?php esc_html_e( 'Please paste the following shortcode into the content of the page where you want to display the chat.', 'fe-ai-search' ); ?>
		</p>
		<code>[fe-ai-search]</code>
		<?php
	}

	/**
	 * Renders the settings for the full-screen chat page.
	 *
	 * Uses a page dropdown to select the dedicated page and conditionally
	 * disables the control when the Pro add-on is not active.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_fullscreen_field_html() {
		$display_options = $this->options['display'] ?? [];
		$should_disable  = ! ( class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) && $this->is_license_active );

		?>
		<fieldset>
			<label for="fe_ai_search_fullscreen_page_id">
				<?php esc_html_e( 'Select Chat-Only Page', 'fe-ai-search' ); ?>
			</label>
			<?php
			$dropdown_html = wp_dropdown_pages(
				[
					'name'              => 'fe_ai_search_settings[display][fullscreen_page_id]',
					'id'                => 'fe_ai_search_fullscreen_page_id',
					'echo'              => 0,
					'selected'          => $display_options['fullscreen_page_id'] ?? 0,
					'show_option_none'  => __( '— Don\'t use a dedicated page —', 'fe-ai-search' ),
					'option_none_value' => '0',
				]
			);

			if ( $should_disable ) {
				$dropdown_html = preg_replace(
					'/<select\b/',
					'<select disabled="disabled"',
					$dropdown_html,
					1
				);
			}

			echo $dropdown_html;
			?>
			<?php if ( ! class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) ) : ?>
				<span class="description">(
					<?php
					printf(
						/* translators: %s: Link to the Pro version. */
						wp_kses_post( __( 'Available in the %s version.', 'fe-ai-search' ) ),
						sprintf(
							'<a href="%s" class="%s">%s</a>',
							'#tab_license',
							'fe-ai-search-change-model-link',
							esc_html__( 'Pro', 'fe-ai-search' )
						)
					)
					?>
				)</span>
			<?php endif; ?>
			<br />
			<span class="description">
				<?php
				esc_html_e(
					'When you access the selected static page here, the full-screen chat UI will be displayed directly.',
					'fe-ai-search'
				);
				?>
			</span>
		</fieldset>
		<?php
	}

	/**
	 * Renders the HTML for the "Chat Text & Colors" settings.
	 *
	 * This method renders the input fields for all chat UI text, labels,
	 * and color options used by the frontend chat UI.
	 */
	public function display_text_color_field_html() {
		$display_options = $this->options['display']['text'] ?? [];
		$ui_options      = $this->options['display']['ui'] ?? [];

		$defaults = [
			'window_title'       => __( 'FE Search AI', 'fe-ai-search' ),
			'greeting_message'   => __( 'Hello! I am FE Search AI. How can I help you today?', 'fe-ai-search' ),
			'placeholder_text'   => __( 'Ask a question about this site…', 'fe-ai-search' ),
			'submit_button_text' => __( 'Send', 'fe-ai-search' ),
			'key_color'          => '#0073aa',
			'background_color'   => '#f5f5f5',
			'text_color'         => '#111111',
		];

		$window_title       = $display_options['window_title'] ?? $defaults['window_title'];
		$greeting_message   = $display_options['greeting_message'] ?? $defaults['greeting_message'];
		$placeholder_text   = $display_options['placeholder_text'] ?? $defaults['placeholder_text'];
		$submit_button_text = $display_options['submit_button_text'] ?? $defaults['submit_button_text'];

		$key_color        = $ui_options['key_color'] ?? $defaults['key_color'];
		$background_color = $ui_options['background_color'] ?? $defaults['background_color'];
		$text_color       = $ui_options['text_color'] ?? $defaults['text_color'];
		$use_gradient     = $ui_options['use_gradient'] ?? true;
		?>
		<p>
			<label for="fe_ai_search_window_title"><?php esc_html_e( 'Chat window title', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="fe_ai_search_window_title"
				name="fe_ai_search_settings[display][text][window_title]"
				value="<?php echo esc_attr( $window_title ); ?>"
				placeholder="<?php echo esc_attr( $defaults['window_title'] ); ?>"
				class="regular-text"
			>
		</p>
		<p>
			<label for="fe_ai_search_greeting_message"><?php esc_html_e( 'First greeting', 'fe-ai-search' ); ?></label>
			<textarea
				id="fe_ai_search_greeting_message"
				name="fe_ai_search_settings[display][text][greeting_message]"
				rows="3"
				class="large-text"
				placeholder="<?php echo esc_attr( $defaults['greeting_message'] ); ?>"
			><?php echo esc_textarea( $greeting_message ); ?></textarea>
		</p>
		<p>
			<label for="fe_ai_search_placeholder_text"><?php esc_html_e( 'Input field placeholders', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="fe_ai_search_placeholder_text"
				name="fe_ai_search_settings[display][text][placeholder_text]"
				value="<?php echo esc_attr( $placeholder_text ); ?>"
				placeholder="<?php echo esc_attr( $defaults['placeholder_text'] ); ?>"
				class="regular-text"
			>
		</p>
		<p>
			<label for="fe_ai_search_submit_button_text"><?php esc_html_e( 'Submit button text', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="fe_ai_search_submit_button_text"
				name="fe_ai_search_settings[display][text][submit_button_text]"
				value="<?php echo esc_attr( $submit_button_text ); ?>"
				placeholder="<?php echo esc_attr( $defaults['submit_button_text'] ); ?>"
				class="regular-text"
			>
		</p>

		<p>
			<label for="fe_ai_search_key_color"><?php esc_html_e( 'Key Color', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="fe_ai_search_key_color"
				name="fe_ai_search_settings[display][ui][key_color]"
				value="<?php echo esc_attr( $key_color ); ?>"
				class="fe-ai-search-color-picker"
			><br />
			<span class="description"><?php esc_html_e( 'Select the basic colors for chat bubbles and user speech balloons.', 'fe-ai-search' ); ?></span>
		</p>
		<p>
			<label for="fe_ai_search_background_color"><?php esc_html_e( 'Chat window background color', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="fe_ai_search_background_color"
				name="fe_ai_search_settings[display][ui][background_color]"
				value="<?php echo esc_attr( $background_color ); ?>"
				class="fe-ai-search-color-picker"
			><br />
			<span class="description"><?php esc_html_e( 'Background color for the chat window and message area.', 'fe-ai-search' ); ?></span>
		</p>
		<p>
			<label for="fe_ai_search_text_color"><?php esc_html_e( 'Base text color', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="fe_ai_search_text_color"
				name="fe_ai_search_settings[display][ui][text_color]"
				value="<?php echo esc_attr( $text_color ); ?>"
				class="fe-ai-search-color-picker"
			><br />
			<span class="description"><?php esc_html_e( 'Default text color used for chat content and labels.', 'fe-ai-search' ); ?></span>
		</p>
		<p>
			<label>
				<input
					type="checkbox"
					name="fe_ai_search_settings[display][ui][use_gradient]"
					value="1"
					<?php checked( (bool) $use_gradient ); ?>
				>
				<?php esc_html_e( 'Display chat background and key color with gradients', 'fe-ai-search' ); ?>
			</label><br />
			<span class="description"><?php esc_html_e( 'When unchecked, the chat UI will use flat colors without gradients.', 'fe-ai-search' ); ?></span>
		</p>

		<script>
			jQuery(document).ready(function($){
				$('.fe-ai-search-color-picker').wpColorPicker();
			});
		</script>
		<?php
	}

	/**
	 * Renders the HTML for the Advanced Display settings.
	 * (e.g., enable/disable CSS and JS)
	 */
	public function display_advanced_field_html() {
		$ui_options = $this->options['display']['ui'] ?? [];

		$enable_css = $ui_options['enable_css'] ?? true;
		$enable_js  = $ui_options['enable_js'] ?? true;
		?>
		<fieldset>
			<label>
				<input
					type="checkbox"
					name="fe_ai_search_settings[display][ui][enable_css]"
					value="1"
					<?php checked( $enable_css, true ); ?>
				/>
				<?php esc_html_e( 'Load default plugin CSS', 'fe-ai-search' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Uncheck this if you want to style the chat UI entirely with your own theme\'s CSS.', 'fe-ai-search' ); ?>
			</p>

			<label>
				<input
					type="checkbox"
					name="fe_ai_search_settings[display][ui][enable_js]"
					value="1"
					<?php checked( $enable_js, true ); ?>
				/>
				<?php esc_html_e( 'Load default plugin JavaScript', 'fe-ai-search' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Uncheck this only if you are completely replacing the chat UI HTML and handling the API communication with your own JavaScript.', 'fe-ai-search' ); ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Renders the input field for limiting sync to specific post IDs.
	 *
	 * When any IDs are provided here, general post-type based sync rules
	 * are ignored and only the specified posts are synchronized.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function include_post_ids_field_html() {
		$sync_options = $this->options['sync'] ?? [];
		$ids          = $sync_options['include']['ids'] ?? '';
		?>
		<input
			type="text"
			name="fe_ai_search_settings[sync][include][ids]"
			value="<?php echo esc_attr( $ids ); ?>"
			class="regular-text"
		>
		<p class="description">
			<?php echo wp_kses_post( __( 'If you want to sync only certain posts, enter the post IDs separated by commas (e.g. 10, 25, 103).<br><strong>Note:</strong> If you enter any here, the "Post types to sync" setting will be ignored and only the posts with those IDs will be synced.', 'fe-ai-search' ) ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the input field for excluding specific post IDs from sync.
	 *
	 * Posts whose IDs are entered here will be skipped even if they match
	 * the post-type based inclusion rules.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function exclude_post_ids_field_html() {
		$sync_options = $this->options['sync'] ?? [];
		$ids          = $sync_options['exclude']['ids'] ?? '';
		?>
		<input
			type="text"
			name="fe_ai_search_settings[sync][exclude][ids]"
			value="<?php echo esc_attr( $ids ); ?>"
			class="regular-text"
		>
		<p class="description">
			<?php
			esc_html_e( 'If you want to exclude posts from synchronization, enter the post IDs separated by commas (e.g., 15, 30).', 'fe-ai-search' );
			?>
		</p>
		<?php
	}

	/**
	 * Renders the numeric input for limiting the total number of posts to sync.
	 *
	 * A positive value limits synchronization to the most recent N posts,
	 * while -1 indicates that all eligible posts should be synchronized.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function sync_limit_field_html() {
		$sync_options = $this->options['sync'] ?? [];
		$limit        = $sync_options['limit'] ?? 100;
		?>
		<input
			type="number"
			name="fe_ai_search_settings[sync][limit]"
			value="<?php echo esc_attr( $limit ); ?>"
			class="small-text"
			min="-1"
		>
		<?php esc_html_e( 'posts', 'fe-ai-search' ); ?>
		<p class="description">
			<?php
			$string1 = __( 'Limits the number of posts to be synchronized to the specified number, starting from the most recent.', 'fe-ai-search' );
			$string2 = __( 'Set this if you are trying to synchronize on a trial basis or want to reduce API costs.<br><strong>-1</strong> will synchronize all eligible posts.', 'fe-ai-search' );
			echo wp_kses_post( $string1 . $string2 );
			?>
		</p>
		<?php
	}

	/**
	 * Renders the checkbox that controls data deletion on plugin uninstall.
	 *
	 * When enabled, all plugin-related tables and options are removed during
	 * uninstallation; otherwise data is preserved for potential re-use.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function delete_on_uninstall_field_html() {
		$advanced_options    = $this->options['advanced'] ?? [];
		$delete_on_uninstall = $advanced_options['delete_on_uninstall'] ?? false;
		?>
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php esc_html_e( 'Uninstallation settings', 'fe-ai-search' ); ?></span>
			</legend>
			<label>
				<input
					type="checkbox"
					name="fe_ai_search_settings[data][options][delete_on_uninstall]"
					value="1"
					<?php checked( $delete_on_uninstall, true ); ?>
				/>
				<?php esc_html_e( 'Completely remove all database tables and settings when removing (uninstalling) a plugin.', 'fe-ai-search' ); ?>
			</label>
			<p class="description">
				<?php
				$string1 = __( 'Check this box if you want to stop using this plugin completely, rather than just temporarily disabling it.', 'fe-ai-search' );
				$string2 = __( 'If you leave this box unchecked, your previous settings and sync data will be restored when you reinstall the plugin.', 'fe-ai-search' );
				echo wp_kses_post( $string1 . '<br>' . $string2 );
				?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Renders the legacy UI for selecting post types to sync.
	 *
	 * This method is retained for backward compatibility and provides a
	 * simple post-type checkbox list used by older sync configurations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function sync_post_types_field_html() {
		$post_types          = get_post_types( [ 'public' => true ], 'objects' );
		$excluded_post_types = [ 'attachment' ];

		$saved_post_types = $this->options['sync']['targets']['post_types'];
		if ( ! is_array( $saved_post_types ) ) {
			$saved_post_types = [ 'post', 'page' ];
		}

		echo '<fieldset>';
		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $excluded_post_types ) ) {
				continue;
			}
			?>
			<label>
				<input
					type="checkbox"
					name="fe_ai_search_settings[sync][targets][post_types][]"
					value="<?php echo esc_attr( $post_type->name ); ?>"
					<?php checked( in_array( $post_type->name, $saved_post_types ) ); ?>
				/>
				<?php echo esc_html( $post_type->label ); ?> (<code><?php echo esc_html( $post_type->name ); ?></code>)
			</label><br>
			<?php
		}
		echo '</fieldset>';
		echo '<p class="description">' . __( 'Select the post types you want to include in AI search.', 'fe-ai-search' ) . '</p>';
	}

	/**
	 * Sanitizes the sync options before saving to the database.
	 *
	 * @param array $input The raw input from the settings form.
	 * @return array The sanitized array.
	 */
	public function sanitize_sync_options( $input ) {
		$new_input  = [];
		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $post_type ) {
			$pt_name = $post_type->name;
			if ( 'attachment' === $pt_name ) {
				continue;
			}

			// 'enabled' checkbox
			$new_input['post_types'][ $pt_name ]['enabled'] = ! empty( $input['post_types'][ $pt_name ]['enabled'] ) ? 1 : 0;

			// Other checkboxes
			$checkboxes = [ 'include_title', 'include_content', 'include_date', 'include_author' ];
			foreach ( $checkboxes as $key ) {
				$new_input['post_types'][ $pt_name ][ $key ] = ! empty( $input['post_types'][ $pt_name ][ $key ] ) ? 1 : 0;
			}

			// Taxonomies
			$new_input['post_types'][ $pt_name ]['taxonomies'] = $input['post_types'][ $pt_name ]['taxonomies'] ?? [];

			// Custom Fields (Pro)
			if ( isset( $input['post_types'][ $pt_name ]['custom_fields'] ) ) {
				$new_input['post_types'][ $pt_name ]['custom_fields'] = sanitize_text_field( $input['post_types'][ $pt_name ]['custom_fields'] );
			}
		}

		return $new_input;
	}

	/**
	 * Normalizes a numeric string by converting full-width characters and stripping non-digits.
	 *
	 * Converts full-width numerals, spaces, and commas to their half-width
	 * equivalents, then removes any character that is not a digit or comma.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Value before being saved.
	 * @return string Value after sanitization.
	 */
	public function sanitize_numeric_string( $input ) {
		// Convert full-width numbers, spaces, and commas to half-width.
		$sanitized_input = mb_convert_kana( $input, 'ns' );
		$sanitized_input = str_replace( '，', ',', $sanitized_input );
		$sanitized_input = preg_replace( '/[^0-9,]/', '', $sanitized_input );

		return $sanitized_input;
	}

	/**
	 * Sanitizes the display options array before saving.
	 *
	 * Currently normalizes the include/exclude ID lists by converting
	 * full-width numbers to half-width, while preserving the rest of the
	 * structure as-is.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Option array before being saved.
	 * @return array Option array after sanitization.
	 */
	public function sanitize_display_options( $input ) {
		$new_input = $input;

		// Convert full-width numbers to half-width.
		if ( isset( $new_input['display_rules']['include_ids'] ) ) {
			// 'n' option converts numbers to half-width.
			$new_input['display_rules']['include_ids'] = mb_convert_kana( $new_input['display_rules']['include_ids'], 'n' );
		}
		if ( isset( $new_input['display_rules']['exclude_ids'] ) ) {
			$new_input['display_rules']['exclude_ids'] = mb_convert_kana( $new_input['display_rules']['exclude_ids'], 'n' );
		}

		return $new_input;
	}

	/**
	 * Renders the main system prompt editor field.
	 *
	 * If no custom prompt is saved, it falls back to the shared default
	 * system prompt from the chat handler class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function system_prompt_field_html() {
		$prompt_options = $this->options['prompt'] ?? [];
		$prompt         = $prompt_options['system_prompt'] ?? '';

		if ( empty( $prompt ) ) {
			$prompt = \FEAISearch\Ajax\FE_AI_Search_Chat_Handler::get_default_system_prompt();
		}
		?>
		<textarea
			name="fe_ai_search_settings[prompt][system_prompt]"
			rows="100"
			class="fe-ai-search-prompt-editor large-text"
		><?php echo esc_textarea( $prompt ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Customize the instructions (system prompts) for the AI assistant. If left blank, the standard prompt will be used.', 'fe-ai-search' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the numeric input for the sync batch size.
	 *
	 * Controls how many posts are processed per batch during synchronization
	 * to balance performance and timeout risk on shared hosting.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function batch_size_field_html() {
		$options    = $this->options['sync'] ?? [];
		$batch_size = $options['batch_size'] ?? 10;
		?>
		<input
			type="number"
			name="fe_ai_search_settings[sync][batch_size]"
			value="<?php echo esc_attr( $batch_size ); ?>"
			class="small-text"
			min="1"
			max="100"
		>
		<?php esc_html_e( 'posts', 'fe-ai-search' ); ?>
		<p class="description">
			<?php esc_html_e( 'Enter the number of posts to process in one batch during synchronization. A smaller number is less likely to cause timeouts on shared servers. (Default: 10, Recommended: 10-100)', 'fe-ai-search' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the HTML for the Debug Mode checkbox.
	 *
	 * @since 1.0.0
	 */
	public function debug_mode_field_html() {
		$advanced_options = $this->options['advanced'] ?? [];
		$is_enabled       = $advanced_options['debug_mode'] ?? false;
		?>
		<fieldset>
			<label>
				<input
					type="checkbox"
					name="fe_ai_search_settings[advanced][debug_mode]"
					value="1"
					<?php checked( $is_enabled, true ); ?>
				/>
				<?php esc_html_e( 'Enable the recording of detailed system logs for debugging purposes.', 'fe-ai-search' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'When enabled, detailed operational logs will be saved to the `{prefix}fe_ai_search_system_logs` table (e.g. wp_fe_ai_search_system_logs if your table prefix is wp_). Only enable this when troubleshooting issues, as it can impact performance.', 'fe-ai-search' ); ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Renders the input field for the AI-visible site name.
	 *
	 * This value is injected into the system prompt in place of the
	 * {site_name} placeholder; defaults to the WordPress site title.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function site_name_field_html() {
		$prompt_options = $this->options['prompt'] ?? [];
		$site_name      = $prompt_options['site_name'] ?? get_bloginfo( 'name' );
		?>
		<input type="text" name="fe_ai_search_settings[prompt][site_name]" value="<?php echo esc_attr( $site_name ); ?>" class="regular-text">
		<p class="description"><?php esc_html_e( 'The name of the site you want the AI to recognize. If left blank, the site title will be used.', 'fe-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Renders the textarea input for the AI-visible site purpose.
	 *
	 * This value is injected into the system prompt in place of the
	 * {site_purpose} placeholder; defaults to the site tagline.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function site_purpose_field_html() {
		$prompt_options = $this->options['prompt'] ?? [];
		$site_purpose   = $prompt_options['site_purpose'] ?? get_bloginfo( 'description' );

		?>
		<textarea name="fe_ai_search_settings[prompt][site_purpose]" rows="3" class="large-text"><?php echo esc_textarea( $site_purpose ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Briefly explain the purpose of this site to the AI. If left blank, the site\'s tagline will be used.', 'fe-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Sanitizes the main settings array before saving to the database.
	 *
	 * This method is the single callback for our master 'fe_ai_search_settings' option.
	 * It receives the raw input from the form, validates/cleans each part,
	 * and merges it with the existing settings to ensure no data is lost.
	 *
	 * @since 1.0.0
	 * @param array $input The raw array of settings data from the $_POST request.
	 * @return array The fully sanitized and merged array to be saved.
	 */
	public function sanitize_main_settings( $input ) {
		// Start with the existing, saved options.
		$new_input = get_option( 'fe_ai_search_settings', [] );

		// --- Provider Tab ---
		$new_input['provider']['openai_key']    = sanitize_text_field( $input['provider']['openai_key'] ?? '' );
		$new_input['provider']['google_key']    = sanitize_text_field( $input['provider']['google_key'] ?? '' );
		$new_input['provider']['anthropic_key'] = sanitize_text_field( $input['provider']['anthropic_key'] ?? '' );
		$new_input['provider']['embedding']     = sanitize_key( $input['provider']['embedding'] ?? 'openai' );
		$new_input['provider']['chat']          = sanitize_key( $input['provider']['chat'] ?? 'openai' );

		// --- Prompt Tab ---
		$new_input['prompt']['site_name']     = sanitize_text_field( $input['prompt']['site_name'] ?? '' );
		$new_input['prompt']['site_purpose']  = sanitize_textarea_field( $input['prompt']['site_purpose'] ?? '' );
		$new_input['prompt']['system_prompt'] = sanitize_textarea_field( $input['prompt']['system_prompt'] ?? '' );

		// --- Sync Tab ---
		// Normalize existing sync options to an array to avoid writing offsets on a string.
		$existing_sync = $new_input['sync'] ?? [];
		if ( ! is_array( $existing_sync ) ) {
			$existing_sync = [];
		}
		// Also normalize existing include/exclude to arrays so that nested ['ids'] writes are safe
		if ( isset( $existing_sync['include'] ) && ! is_array( $existing_sync['include'] ) ) {
			$existing_sync['include'] = [ 'ids' => $existing_sync['include'] ];
		}
		if ( isset( $existing_sync['exclude'] ) && ! is_array( $existing_sync['exclude'] ) ) {
			$existing_sync['exclude'] = [ 'ids' => $existing_sync['exclude'] ];
		}
		$new_input['sync'] = $existing_sync;

		// Normalize raw sync input to an array first to avoid accessing offsets on a string.
		$sync_input = $input['sync'] ?? [];
		if ( ! is_array( $sync_input ) ) {
			$sync_input = [];
		}
		$new_input['sync']['limit']      = intval( $sync_input['limit'] ?? -1 );
		$new_input['sync']['batch_size'] = absint( $sync_input['batch_size'] ?? 10 );
		// Per-post-type targets (post types, taxonomies, custom fields).
		$new_input['sync']['targets'] = $this->sanitize_sync_targets( $sync_input['targets'] ?? [] );
		// Normalize include / exclude to arrays before accessing ['ids'] to avoid TypeError
		$raw_include = $sync_input['include'] ?? [];
		if ( ! is_array( $raw_include ) ) {
			$raw_include = [ 'ids' => $raw_include ];
		}
		$raw_exclude = $sync_input['exclude'] ?? [];
		if ( ! is_array( $raw_exclude ) ) {
			$raw_exclude = [ 'ids' => $raw_exclude ];
		}
		// Include / Exclude IDs are stored under sync['include']['ids'] / sync['exclude']['ids'].
		$new_input['sync']['include']['ids'] = $this->sanitize_numeric_string( $raw_include['ids'] ?? '' );
		$new_input['sync']['exclude']['ids'] = $this->sanitize_numeric_string( $raw_exclude['ids'] ?? '' );

		// --- Display Tab ---
		$new_input['display']['ui']       = $this->sanitize_display_ui( $input['display']['ui'] ?? [] );
		$new_input['display']['text']     = $this->sanitize_display_text( $input['display']['text'] ?? [] );
		$new_input['display']['links']    = $this->sanitize_display_links( $input['display']['links'] ?? [] );
		$new_input['display']['floating'] = $this->sanitize_display_floating( $input['display']['floating'] ?? [] );

		// --- Data Tab ---
		$new_input['advanced']['delete_on_uninstall'] = ! empty( $input['advanced']['delete_on_uninstall'] );
		$new_input['advanced']['debug_mode']          = ! empty( $input['advanced']['debug_mode'] );

		// License data is managed by a dedicated option (fe_ai_search_license).
		// DB version is handled by the activator, not here.
		$new_input['advanced']['db_version'] = $this->options['advanced']['db_version'] ?? '1.0';

		// --- Preserve sync status/settings ---
		// Ajax handlers (Rebuild / Smart Sync) update sync['status'] and sync['settings']
		// directly via update_option(). Those runtime fields are not part of the settings
		// form submission, so they would be dropped if we didn't explicitly carry them
		// over from the existing saved options.
		$existing_options = get_option( 'fe_ai_search_settings', [] );

		$existing_sync = $existing_options['sync'] ?? [];
		if ( is_array( $existing_sync ) ) {
			if ( isset( $existing_sync['status'] ) && is_array( $existing_sync['status'] ) ) {
				$new_input['sync']['status'] = $existing_sync['status'];
			}
			if ( isset( $existing_sync['settings'] ) && is_array( $existing_sync['settings'] ) ) {
				// If sanitize_main_settings already created a settings array (e.g. in a
				// future version), merge, preferring the more recent values in $new_input.
				$merged_settings = $existing_sync['settings'];
				if ( isset( $new_input['sync']['settings'] ) && is_array( $new_input['sync']['settings'] ) ) {
					$merged_settings = array_merge( $merged_settings, $new_input['sync']['settings'] );
				}
				$new_input['sync']['settings'] = $merged_settings;
			}
		}

		return $new_input;
	}

	/**
	 * Sanitizes the 'sync[targets]' array.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized array.
	 */
	private function sanitize_sync_targets( $input ) {
		$new_input  = [];
		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $post_type ) {
			$pt_name = $post_type->name;
			if ( 'attachment' === $pt_name ) {
				continue;
			}

			if ( ! isset( $input[ $pt_name ] ) ) {
				continue;
			}

			$new_input[ $pt_name ]['enabled']              = ! empty( $input[ $pt_name ]['enabled'] );
			$new_input[ $pt_name ]['include_title']        = ! empty( $input[ $pt_name ]['include_title'] );
			$new_input[ $pt_name ]['include_content']      = ! empty( $input[ $pt_name ]['include_content'] );
			$new_input[ $pt_name ]['include_date']         = ! empty( $input[ $pt_name ]['include_date'] );
			$new_input[ $pt_name ]['include_author']       = ! empty( $input[ $pt_name ]['include_author'] );
			$new_input[ $pt_name ]['enable_custom_fields'] = ! empty( $input[ $pt_name ]['enable_custom_fields'] );

			// Taxonomies
			if ( isset( $input[ $pt_name ]['taxonomies'] ) && is_array( $input[ $pt_name ]['taxonomies'] ) ) {
				$new_input[ $pt_name ]['taxonomies'] = array_map( 'sanitize_key', $input[ $pt_name ]['taxonomies'] );
			} else {
				$new_input[ $pt_name ]['taxonomies'] = [];
			}

			// Custom Fields (Pro)
			if ( isset( $input[ $pt_name ]['custom_fields'] ) ) {
				$new_input[ $pt_name ]['custom_fields'] = sanitize_text_field( $input[ $pt_name ]['custom_fields'] );
			}
		}
		return $new_input;
	}

	/**
	 * Sanitizes the 'display[ui]' array.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized array.
	 */
	private function sanitize_display_ui( $input ) {
		$new_input                     = [];
		$new_input['key_color']        = sanitize_hex_color( $input['key_color'] ?? '#0073aa' );
		$new_input['background_color'] = sanitize_hex_color( $input['background_color'] ?? '#f5f5f5' );
		$new_input['text_color']       = sanitize_hex_color( $input['text_color'] ?? '#111111' );
		$new_input['animation_speed']  = absint( $input['animation_speed'] ?? 3 );
		// Validate send_mode string (enter / shift_enter / cmd_enter).
		$send_mode = $input['send_mode'] ?? 'enter';
		if ( ! in_array( $send_mode, [ 'enter', 'shift_enter', 'cmd_enter' ], true ) ) {
			$send_mode = 'enter';
		}
		$new_input['send_mode']    = $send_mode;
		$new_input['enable_css']   = ! empty( $input['enable_css'] );
		$new_input['enable_js']    = ! empty( $input['enable_js'] );
		$new_input['use_gradient'] = ! empty( $input['use_gradient'] );

		return $new_input;
	}

	/**
	 * Sanitizes the 'display[text]' array.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized array.
	 */
	private function sanitize_display_text( $input ) {
		$new_input                       = [];
		$new_input['window_title']       = sanitize_text_field( $input['window_title'] ?? '' );
		$new_input['greeting_message']   = sanitize_textarea_field( $input['greeting_message'] ?? '' );
		$new_input['placeholder_text']   = sanitize_text_field( $input['placeholder_text'] ?? '' );
		$new_input['submit_button_text'] = sanitize_text_field( $input['submit_button_text'] ?? '' );
		return $new_input;
	}

	/**
	 * Sanitizes the 'display[links]' array.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized array.
	 */
	private function sanitize_display_links( $input ) {
		$new_input                    = [];
		$new_input['terms_page_id']   = absint( $input['terms_page_id'] ?? 0 );
		$new_input['privacy_page_id'] = absint( $input['privacy_page_id'] ?? 0 );
		return $new_input;
	}

	/**
	 * Sanitizes the 'display[floating]' array.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized array.
	 */
	private function sanitize_display_floating( $input ) {
		$new_input                         = [];
		$new_input['enable_floating_mode'] = ! empty( $input['enable_floating_mode'] );
		$new_input['require_login']        = ! empty( $input['require_login'] );
		$new_input['display_on_pc']        = ! empty( $input['display_on_pc'] );
		$new_input['display_on_mobile']    = ! empty( $input['display_on_mobile'] );
		// Login status visibility flags.
		$new_input['show_to_logged_in_users'] = ! empty( $input['show_to_logged_in_users'] );
		$new_input['show_to_guests']          = ! empty( $input['show_to_guests'] );
		$new_input['fullscreen_page_id']      = absint( $input['fullscreen_page_id'] ?? 0 );

		// Rules
		$rules = $input['display_rules'] ?? [];
		$new_input['display_rules']['show_on_front_page'] = ! empty( $rules['show_on_front_page'] );
		$new_input['display_rules']['show_on_archives']   = ! empty( $rules['show_on_archives'] );
		$new_input['display_rules']['show_on_search']     = ! empty( $rules['show_on_search'] );
		$new_input['display_rules']['show_on_404']        = ! empty( $rules['show_on_404'] );
		$new_input['display_rules']['show_on_singular']   = ! empty( $rules['show_on_singular'] );
		$new_input['display_rules']['include_ids']        = $this->sanitize_numeric_string( $rules['include_ids'] ?? '' );
		$new_input['display_rules']['exclude_ids']        = $this->sanitize_numeric_string( $rules['exclude_ids'] ?? '' );

		return $new_input;
	}

	/**
	 * Renders the HTML for the "Interaction" settings (Animation Speed, Shift+Enter).
	 */
	public function display_interaction_field_html() {
		$ui_options      = $this->options['display']['ui'] ?? [];
		$animation_speed = $ui_options['animation_speed'] ?? 5;
		$send_mode       = $ui_options['send_mode'] ?? 'enter';
		?>
		<div>
			<label><?php esc_html_e( 'Typing Animation Speed', 'fe-ai-search' ); ?></label>
			<div class="fe-ai-search-wrapper slider">
				<span><?php esc_html_e( 'Smooth', 'fe-ai-search' ); ?></span>
				<input
					type="range"
					id="fe_ai_search_animation_speed_slider"
					name="fe_ai_search_settings[display][ui][animation_speed]"
					min="1" max="10" step="1"
					value="<?php echo esc_attr( $animation_speed ); ?>"
				>
				<span><?php esc_html_e( 'Fast', 'fe-ai-search' ); ?></span>
				<span id="fe_ai_search_animation_speed_value">
					<?php echo esc_html( $animation_speed ); ?>
				</span>
			</div>
			<br />
			<span class="description">
				<?php esc_html_e( 'Adjust the typing animation speed of the AI\'s response.', 'fe-ai-search' ); ?>
			</span>
		</div>

		<div>
			<label for="fe_ai_search_send_mode"><?php esc_html_e( 'Send Key Settings (Default)', 'fe-ai-search' ); ?></label>
			<select name="fe_ai_search_settings[display][ui][send_mode]" id="fe_ai_search_send_mode">
				<option value="enter" <?php selected( $send_mode, 'enter' ); ?>>
					<?php esc_html_e( 'Enter (Shift+Enter for newline)', 'fe-ai-search' ); ?>
				</option>
				<option value="shift_enter" <?php selected( $send_mode, 'shift_enter' ); ?>>
					<?php esc_html_e( 'Shift+Enter (Enter for newline)', 'fe-ai-search' ); ?>
				</option>
				<option value="cmd_enter" <?php selected( $send_mode, 'cmd_enter' ); ?>>
					<?php esc_html_e( 'Cmd/Ctrl+Enter (Enter for newline)', 'fe-ai-search' ); ?>
				</option>
			</select>
		</div>
		<?php
	}

	/**
	 * Renders the HTML for the "Legal Links" settings (Terms, Privacy).
	 */
	public function display_links_field_html() {
		$links_options = $this->options['display']['links'] ?? [];
		?>
		<div>
			<label for="fe_ai_search_terms_page_id"><?php esc_html_e( 'Terms of Service Page', 'fe-ai-search' ); ?></label>
			<?php
			wp_dropdown_pages(
				[
					'name'              => 'fe_ai_search_settings[display][links][terms_page_id]',
					'selected'          => $links_options['terms_page_id'] ?? 0,
					'show_option_none'  => '— ' . __( 'Not selected', 'fe-ai-search' ) . ' —',
					'option_none_value' => '0',
				]
			);
			?>
		</div>

		<div>
			<label for="fe_ai_search_privacy_page_id"><?php esc_html_e( 'Privacy Policy Page', 'fe-ai-search' ); ?></label>
			<?php
			wp_dropdown_pages(
				[
					'name'              => 'fe_ai_search_settings[display][links][privacy_page_id]',
					'selected'          => $links_options['privacy_page_id'] ?? 0,
					'show_option_none'  => '— ' . __( 'Not selected', 'fe-ai-search' ) . ' —',
					'option_none_value' => '0',
				]
			);
			?>
			<br />
			<span class="description">
				<?php esc_html_e( 'If not selected, the page set in WordPress\'s standard privacy settings will be used.', 'fe-ai-search' ); ?>
			</span>
		</div>
		<?php
	}
}
