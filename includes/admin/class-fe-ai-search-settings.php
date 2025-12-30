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

namespace FESearchAI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FEAISearch\Core\FE_AI_Search_License;
use FEAISearch\Core\FE_AI_Search_Encryption_Helper;

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
class FE_Search_AI_Settings {

	private $options            = [];
	private $is_license_active  = false;
	private $license_alert_icon = '';

	public function __construct() {

		// Retrieve all configuration data
		$this->options = get_option( 'fe_ai_search_settings', [] );

		// Treat the license as active when the status is "active" and the product ID
		// matches the Pro add-on (productId = 65 in License Manager for WooCommerce).
		$this->is_license_active = FE_AI_Search_License::is_pro_active();

		if ( class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' ) && ! $this->is_license_active ) {
			$this->license_alert_icon = '<a href="' . admin_url( 'admin.php' )
			. '?page=fe-ai-search#tab_license">'
			. '<span class="dashicons dashicons-warning"></span></a>';
		}

		add_action( 'admin_init', [ $this, 'settings_init' ] );
		add_action( 'wp_ajax_fe_ai_search_delete_system_logs', [ $this, 'ajax_delete_system_logs' ] );
		add_action( 'wp_ajax_fe_ai_search_delete_conversation_logs', [ $this, 'ajax_delete_conversation_logs' ] );
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
		// Determine if the Pro license is active and the Pro add-on is installed.
		$has_pro_class = class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' );
		$is_pro        = ( FE_AI_Search_License::is_pro_active() && $has_pro_class );
		?>
		<div class="wrap">

			<?php
			// Display any settings errors
			settings_errors();

			// Get user locale
			$locale = get_user_locale();
			?>

			<?php \FESearchAI\Admin\FE_Search_AI_Admin::render_plugin_header( $is_pro ); ?>

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

			<form action="options.php" method="post">
				<div id="fe_ai_search_settings_inner">
					<?php
					// Use the correct settings group name defined in settings_init()
					settings_fields( 'fe-ai-search-settings-group' );
					?>

					<div id="tab_provider" class="tab-content">
						<details id="fe_ai_search_api_usage_limits">
							<summary>
								<?php esc_html_e( 'API usage limits and costs', 'fe-ai-search' ); ?>
							</summary>
							<div>
								<p>
									<?php esc_html_e( 'Generate an API key in the dashboard of each AI provider you plan to use and enter it in the fields below. These APIs are billed based on usage. Before enabling the chat, please review each provider\'s pricing. To help prevent unexpected token usage due to misconfiguration or abuse, this plugin applies built-in rate limits.', 'fe-ai-search' ); ?>
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
											'<a href="https://fe-search.com/docs/ai" target="_blank" rel="noopener noreferrer">',
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

						<?php do_settings_sections( 'fe_ai_search_vector_store_section' ); ?>
						<table class="form-table">
							<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_vector_store_section' ); ?>
						</table>
						<?php // Pro add-on: tuning (Custom Stop Words) now lives at the end of the Sync tab. ?>
						<?php if ( $is_pro ) : ?>
							<?php do_settings_sections( 'fe_ai_search_vector_store_section_pro' ); ?>
							<table class="form-table">
								<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_vector_store_section_pro' ); ?>
							</table>
							<?php do_settings_sections( 'fe_ai_search_tuning_section_pro' ); ?>
							<table class="form-table">
								<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_tuning_section_pro' ); ?>
							</table>
						<?php endif; ?>
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
						<?php if ( $is_pro ) : ?>
							<?php do_settings_sections( 'fe_ai_search_privacy_section_pro' ); ?>
							<table class="form-table">
								<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_privacy_section_pro' ); ?>
							</table>
						<?php endif; ?>
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
						<?php do_settings_sections( 'fe_ai_search_qdrant_section' ); ?>
						<table class="form-table">
							<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_qdrant_section' ); ?>
						</table>
						<?php if ( $is_pro ) : ?>
						<?php endif; ?>
						<?php // Data management (previously in its own "Data" tab). ?>
						<?php do_settings_sections( 'fe_ai_search_data_section' ); ?>
						<table class="form-table">
							<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_data_section' ); ?>
						</table>
						<?php // Pro add-on: external API token section only. ?>
						<?php if ( $is_pro ) : ?>
							<?php do_settings_sections( 'fe_ai_search_api_token_section_pro' ); ?>
							<table class="form-table">
								<?php do_settings_fields( 'fe-ai-search', 'fe_ai_search_api_token_section_pro' ); ?>
							</table>
						<?php endif; ?>
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
				</div>

				<div id="fe_ai_search_settings_footer">
					<?php submit_button( __( 'Save Settings', 'fe-ai-search' ) ); ?>
					<?php if ( 'ja' === $locale || 'ja_JP' === $locale ) : ?>
						<!-- Begin Yahoo! JAPAN Web Services Attribution Snippet -->
						<span style="margin:15px 15px 15px 15px"><a href="https://developer.yahoo.co.jp/sitemap/">Webサービス by Yahoo! JAPAN</a></span>
						<!-- End Yahoo! JAPAN Web Services Attribution Snippet -->
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Initialize the Settings API: Register the master setting and define sections/fields.
	 *
	 * This method registers the main settings group and defines all sections
	 * and fields for the settings page, including provider configuration,
	 * sync options, display settings, prompts, and advanced options.
	 *
	 * @since 1.0.0
	 * @return void
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
		add_settings_field( 'fe_ai_search_vector_store', __( 'Data Storage', 'fe-ai-search' ), [ $this, 'vector_store_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );
		add_settings_field( 'fe_ai_search_sync_limit', __( 'Sync Limit', 'fe-ai-search' ), [ $this, 'sync_limit_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );
		add_settings_field( 'fe_ai_search_batch_size', __( 'Batch Size', 'fe-ai-search' ), [ $this, 'batch_size_field_html' ], $page_slug, 'fe_ai_search_sync_options_section' );

		// Advanced Sync Section
		add_settings_section( 'fe_ai_search_sync_advanced_section', __( 'Advanced Sync Settings', 'fe-ai-search' ), null, $page_slug );

		// ------------------
		// Display Tab
		// ------------------
		// Appearance Section
		add_settings_section( 'fe_ai_search_display_appearance_section', __( 'Chat UI Appearance', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_display_chat_text', __( 'Text & Colors', 'fe-ai-search' ), [ $this, 'display_text_color_field_html' ], $page_slug, 'fe_ai_search_display_appearance_section' );
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
		add_settings_field( 'fe_ai_search_site_name', __( 'Site Name (AI)', 'fe-ai-search' ), [ $this, 'site_name_field_html' ], $page_slug, 'fe_ai_search_prompt_section' );
		add_settings_field( 'fe_ai_search_site_purpose', __( 'Site Purpose (AI)', 'fe-ai-search' ), [ $this, 'site_purpose_field_html' ], $page_slug, 'fe_ai_search_prompt_section' );
		add_settings_field( 'fe_ai_search_system_prompt', __( 'Base System Prompt', 'fe-ai-search' ), [ $this, 'system_prompt_field_html' ], $page_slug, 'fe_ai_search_prompt_section' );

		// ------------------
		// Data Tab
		// ------------------
		add_settings_section( 'fe_ai_search_data_section', __( 'Data Management', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_delete_vectors_ui', __( 'Delete Synced Data', 'fe-ai-search' ), [ $this, 'delete_vectors_ui_field_html' ], $page_slug, 'fe_ai_search_data_section' );
		add_settings_field( 'fe_ai_search_delete_system_logs_ui', __( 'Delete System Logs', 'fe-ai-search' ), [ $this, 'delete_system_logs_ui_field_html' ], $page_slug, 'fe_ai_search_data_section' );
		add_settings_field( 'fe_ai_search_delete_conversation_logs_ui', __( 'Delete Conversation Logs', 'fe-ai-search' ), [ $this, 'delete_conversation_logs_ui_field_html' ], $page_slug, 'fe_ai_search_data_section' );
		add_settings_field( 'fe_ai_search_delete_on_uninstall', __( 'Delete Data on Uninstall', 'fe-ai-search' ), [ $this, 'delete_on_uninstall_field_html' ], $page_slug, 'fe_ai_search_data_section' );

		// ------------------
		// Advanced Tab
		// ------------------
		add_settings_section( 'fe_ai_search_advanced_section', __( 'Advanced Settings', 'fe-ai-search' ), null, $page_slug );
		add_settings_field( 'fe_ai_search_display_advanced', __( 'Assets Loading', 'fe-ai-search' ), [ $this, 'display_advanced_field_html' ], $page_slug, 'fe_ai_search_advanced_section' );
		add_settings_field( 'fe_ai_search_debug_mode_enabled', __( 'Debug Mode', 'fe-ai-search' ), [ $this, 'debug_mode_field_html' ], $page_slug, 'fe_ai_search_advanced_section' );
		add_settings_field( 'fe_ai_search_log_retention_days', __( 'Log Retention (days)', 'fe-ai-search' ), [ $this, 'log_retention_days_field_html' ], $page_slug, 'fe_ai_search_advanced_section' );
		$locale = get_locale();
		if ( 'ja' === $locale || 'ja_JP' === $locale ) {
			add_settings_field( 'fe_ai_search_japanese_tokenizer', __( 'Japanese Tokenizer', 'fe-ai-search' ), [ $this, 'japanese_tokenizer_field_html' ], $page_slug, 'fe_ai_search_advanced_section' );
		}

		// Qdrant connection settings (endpoint, API key, collection) shown in Advanced tab.
		add_settings_section( 'fe_ai_search_qdrant_section', null, null, $page_slug );
		add_settings_field( 'fe_ai_search_qdrant_settings', __( 'Qdrant Settings', 'fe-ai-search' ), [ $this, 'qdrant_settings_field_html' ], $page_slug, 'fe_ai_search_qdrant_section' );
	}

	/**
	 * Renders the HTML for the vector storage configuration section.
	 *
	 * This method outputs radio buttons for selecting between MariaDB/MySQL
	 * and Qdrant as the storage backend for vector embeddings and chunk data.
	 * It displays database engine information and configuration guidance.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function vector_store_field_html() {
		$vector     = $this->options['vector'] ?? [];
		$current    = $vector['store'] ?? 'mariadb';
		$endpoint   = $vector['qdrant']['endpoint'] ?? '';
		$collection = $vector['qdrant']['collection'] ?? '';
		global $wpdb;
		$db_version = method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
		$db_engine  = '';
		if ( false !== stripos( $db_version, 'mariadb' ) ) {
			$db_engine = 'MariaDB';
		} elseif ( false !== stripos( $db_version, 'mysql' ) ) {
			$db_engine = 'MySQL';
		}
		$base_label   = __( 'WordPress database', 'fe-ai-search' );
		$engine_label = $db_engine ? sprintf( '%s (%s)', $db_engine, $base_label ) : $base_label;
		?>
		<div class="fe-ai-search-boxed-option">
			<p>
				<?php esc_html_e( 'Choose where chunk data (and, when applicable, vector data) are stored.', 'fe-ai-search' ); ?>
			</p>
			<fieldset>
				<label>
					<input
						type="radio"
						name="fe_ai_search_settings[vector][store]"
						value="mariadb"
						<?php checked( $current, 'mariadb' ); ?>
					>
					<?php echo esc_html( $engine_label ); ?>
					<br>
					<span style="display:inline-block; margin-left:24px; color:#666; font-size:11px;">
						<?php esc_html_e( 'When \'WordPress database\' is selected, it performs keyword index search based on chunk data without generating vector data, allowing you to run simple AI search without using a dedicated vector database.', 'fe-ai-search' ); ?>
					</span>
				</label>
				<label>
					<input
						type="radio"
						name="fe_ai_search_settings[vector][store]"
						value="qdrant"
						<?php checked( $current, 'qdrant' ); ?>
					>
					<?php esc_html_e( 'Qdrant (external vector database)', 'fe-ai-search' ); ?>
				</label>
			</fieldset>
			<p class="description">
				<?php
				if ( ! empty( $endpoint ) && ! empty( $collection ) ) {
					esc_html_e( 'Qdrant connection settings are configured in the Advanced settings tab.', 'fe-ai-search' );
				} else {
					esc_html_e( 'To use Qdrant, configure the endpoint, API key, and collection in the Advanced settings tab.', 'fe-ai-search' );
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the HTML for the Qdrant vector database settings section.
	 *
	 * This method outputs input fields for Qdrant endpoint URL, API key,
	 * and collection name configuration. It provides descriptions for
	 * each field and handles secure API key management.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function qdrant_settings_field_html() {
		$vector        = $this->options['vector'] ?? [];
		$endpoint      = $vector['qdrant']['endpoint'] ?? '';
		$collection    = $vector['qdrant']['collection'] ?? '';
		$encrypted_key = $vector['qdrant']['api_key'] ?? '';
		$api_key       = FE_AI_Search_Encryption_Helper::decrypt( $encrypted_key );
		?>
		<div class="fe-ai-search-boxed-option">
			<p class="description">
				<?php esc_html_e( 'Configure the connection to your Qdrant Cloud or self-hosted Qdrant instance.', 'fe-ai-search' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="fe_ai_search_qdrant_endpoint"><?php esc_html_e( 'Qdrant Endpoint', 'fe-ai-search' ); ?></label>
					</th>
					<td>
						<input
							type="url"
							id="fe_ai_search_qdrant_endpoint"
							name="fe_ai_search_settings[vector][qdrant][endpoint]"
							value="<?php echo esc_attr( $endpoint ); ?>"
							class="regular-text"
							placeholder="https://your-instance.qdrant.io:6333"
						>
						<p class="description">
							<?php esc_html_e( 'Enter the base URL of your Qdrant HTTP API (including port).', 'fe-ai-search' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="fe_ai_search_qdrant_api_key"><?php esc_html_e( 'Qdrant API Key', 'fe-ai-search' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="fe_ai_search_qdrant_api_key"
							name="fe_ai_search_settings[vector][qdrant][api_key]"
							value="<?php echo esc_attr( $api_key ); ?>"
							class="regular-text"
						>
						<p class="description">
							<?php esc_html_e( 'If you leave this field empty, the previously saved API key will be kept.', 'fe-ai-search' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="fe_ai_search_qdrant_collection"><?php esc_html_e( 'Collection Name', 'fe-ai-search' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="fe_ai_search_qdrant_collection"
							name="fe_ai_search_settings[vector][qdrant][collection]"
							value="<?php echo esc_attr( $collection ); ?>"
							class="regular-text"
							placeholder="collection-1"
						>
						<p class="description">
							<?php esc_html_e( 'The Qdrant collection name used for this site. It should match the collection used during synchronization.', 'fe-ai-search' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the HTML for the chat provider selection section.
	 *
	 * This method outputs a dropdown menu for selecting the AI chat provider
	 * (OpenAI, Google, Anthropic, etc.) with support for provider filtering
	 * via hooks. It displays the current selection and provider descriptions.
	 *
	 * @since 1.0.0
	 * @return void
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

	/**
	 * Renders the HTML for the embedding provider selection section.
	 *
	 * This method outputs a dropdown menu for selecting the AI embedding
	 * provider used for vectorization. It supports provider filtering via
	 * hooks and includes descriptions for each available provider.
	 *
	 * @since 1.0.0
	 * @return void
	 */
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
			esc_html_e( 'Select the embedding AI model used for site search. This model generates vector data to determine the relevance between user questions and content.', 'fe-ai-search' );
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

	/**
	 * Renders the HTML for the OpenAI API key configuration.
	 *
	 * This method outputs the API key input field, connection test button,
	 * and model display information. It includes conditional logic for
	 * Pro version integration and model change links.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function openai_api_key_field_html() {
		$encrypted_key = $this->options['provider']['openai_key'] ?? '';
		$api_key       = FE_AI_Search_Encryption_Helper::decrypt( $encrypted_key );

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
							wp_kses_post( __( 'Model selection is available in the %s version.', 'fe-ai-search' ) ),
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

	/**
	 * Renders the HTML for the Google (Gemini) API key configuration.
	 *
	 * This method outputs the API key input field, connection test button,
	 * and model display information. It includes conditional logic for
	 * Pro version integration and model change links.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function google_api_key_field_html() {
		$encrypted_key = $this->options['provider']['google_key'] ?? '';
		$api_key       = FE_AI_Search_Encryption_Helper::decrypt( $encrypted_key );

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
							wp_kses_post( __( 'Model selection is available in the %s version.', 'fe-ai-search' ) ),
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

	/**
	 * Renders the HTML for the Anthropic (Claude) API key configuration.
	 *
	 * This method outputs the API key input field, connection test button,
	 * and model display information. It includes conditional logic for
	 * Pro version integration and model change links.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function anthropic_api_key_field_html() {
		$encrypted_key = $this->options['provider']['anthropic_key'] ?? '';
		$api_key       = FE_AI_Search_Encryption_Helper::decrypt( $encrypted_key );

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
							wp_kses_post( __( 'Model selection is available in the %s version.', 'fe-ai-search' ) ),
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


	/**
	 * Renders the HTML for the sync options configuration section.
	 *
	 * This method outputs a comprehensive accordion interface for configuring
	 * synchronization targets per post type, including metadata selection
	 * (title, content, date, author), taxonomy configuration with include/exclude
	 * term IDs, and custom field integration for Pro users.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function sync_options_field_html() {
		// New sync schema: per-post-type options are stored under sync['targets'][post_type].
		$sync_targets = $this->options['sync']['targets'] ?? [];
		$is_pro       = ( $this->is_license_active && class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) );

		$post_types          = get_post_types( [ 'public' => true ], 'objects' );
		$excluded_post_types = [ 'attachment' ];

		$string = __( 'Select the post types to target for AI search and the related data to include in chunk data passed to AI. If no items are checked under "include in Chunk Data" for a post type, that post type will be skipped during synchronization.', 'fe-ai-search' );
		echo '<p class="description">' . wp_kses_post( $string ) . '</p>';
		echo '<div id="fe_ai_search_sync_options_accordion" class="fe-ai-search-accordion-wrapper">';

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $excluded_post_types, true ) ) {
				continue;
			}

			// Get saved options for this post type from sync['targets'][post_type].
			$pt_options                  = $sync_targets[ $post_type->name ] ?? [];
			$is_enabled                  = $pt_options['enabled'] ?? in_array( $post_type->name, [ 'post', 'page' ], true );
			$snippet_title               = $pt_options['snippet_include_title'] ?? true;
			$snippet_content             = $pt_options['snippet_include_content'] ?? true;
			$snippet_date                = $pt_options['snippet_include_date'] ?? false;
			$snippet_author              = $pt_options['snippet_include_author'] ?? false;
			$snippet_taxonomies          = isset( $pt_options['snippet_taxonomies'] ) && is_array( $pt_options['snippet_taxonomies'] ) ? $pt_options['snippet_taxonomies'] : [];
			$enable_custom_fields        = $pt_options['enable_custom_fields'] ?? false;
			$snippet_custom_fields_value = $pt_options['snippet_custom_fields'] ?? '';
			$snippet_field_input_id      = sanitize_html_class( 'fe-ai-search-snippet-cf-' . $post_type->name );
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
							<table class="widefat striped fe-ai-search-sync-targets-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Metadata', 'fe-ai-search' ); ?></th>
										<th><?php esc_html_e( 'Include in Chunk Data', 'fe-ai-search' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><?php printf( esc_html__( '%1$s (%2$s)', 'fe-ai-search' ), esc_html__( 'Post Title', 'fe-ai-search' ), 'post_title' ); ?></td>
										<td>
											<label>
												<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][snippet_include_title]" value="1" <?php checked( $snippet_title ); ?>>
											</label>
										</td>
									</tr>
									<tr>
										<td><?php printf( esc_html__( '%1$s (%2$s)', 'fe-ai-search' ), esc_html__( 'Post Content', 'fe-ai-search' ), 'post_content' ); ?></td>
										<td>
											<label>
												<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][snippet_include_content]" value="1" <?php checked( $snippet_content ); ?>>
											</label>
										</td>
									</tr>
									<tr>
										<td><?php printf( esc_html__( '%1$s (%2$s)', 'fe-ai-search' ), esc_html__( 'Post Date', 'fe-ai-search' ), 'post_date' ); ?></td>
										<td>
											<label>
												<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][snippet_include_date]" value="1" <?php checked( $snippet_date ); ?>>
											</label>
										</td>
									</tr>
									<tr>
										<td><?php printf( esc_html__( '%1$s (%2$s)', 'fe-ai-search' ), esc_html__( 'Post Author', 'fe-ai-search' ), 'usermeta > nickname' ); ?></td>
										<td>
											<label>
												<input type="checkbox" name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][snippet_include_author]" value="1" <?php checked( $snippet_author ); ?>>
											</label>
										</td>
									</tr>
									<?php
									$taxonomies = get_object_taxonomies( $post_type->name, 'objects' );
									if ( ! empty( $taxonomies ) ) {
										foreach ( $taxonomies as $tax ) {
											if ( ! $tax->public ) {
												continue;
											}
											$snippet_tax_behavior     = $pt_options['snippet_taxonomies'][ $tax->name ]['behavior'] ?? 'include_terms';
											$snippet_tax_term_ids     = $pt_options['snippet_taxonomies'][ $tax->name ]['term_ids'] ?? '';
											$snippet_tax_enabled      = ! empty( $pt_options['snippet_taxonomies'][ $tax->name ]['enabled'] );
											$tax_enabled_checkbox_id  = sanitize_html_class( 'fe-ai-search-snippet-tax-enabled-' . $post_type->name . '-' . $tax->name );
											$tax_behavior_radio_name  = "fe_ai_search_settings[sync][targets][{$post_type->name}][snippet_taxonomies][{$tax->name}][behavior]";
											$tax_term_ids_name        = "fe_ai_search_settings[sync][targets][{$post_type->name}][snippet_taxonomies][{$tax->name}][term_ids]";
											$tax_config_wrapper_class = sanitize_html_class( 'fe-ai-search-tax-config-wrapper-' . $post_type->name . '-' . $tax->name );
											?>
											<tr>
												<td><?php printf( esc_html__( '%1$s (%2$s)', 'fe-ai-search' ), esc_html( $tax->label ), esc_html( $tax->name ) ); ?></td>
												<td>
													<div>
														<label>
															<input
																type="checkbox"
																id="<?php echo esc_attr( $tax_enabled_checkbox_id ); ?>"
																class="fe-ai-search-snippet-tax-enabled"
																data-target-wrapper-class="<?php echo esc_attr( $tax_config_wrapper_class ); ?>"
																name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][snippet_taxonomies][<?php echo esc_attr( $tax->name ); ?>][enabled]"
																value="1"
																<?php checked( $snippet_tax_enabled ); ?>
															>
															<?php // esc_html_e( 'Include this taxonomy in chunk data', 'fe-ai-search' ); ?>
														</label>
													</div>
													<div class="<?php echo esc_attr( $tax_config_wrapper_class ); ?> fe-ai-search-tax-config-wrapper" style="margin-top: 0.5em; padding-left: 2em; <?php echo $snippet_tax_enabled ? '' : 'display: none;'; ?>">
														<div style="margin-bottom: 0.5em;">
															<label style="margin-right: 1em;">
																<input type="radio" name="<?php echo esc_attr( $tax_behavior_radio_name ); ?>" value="include_terms" <?php checked( $snippet_tax_behavior, 'include_terms' ); ?>>
																<?php esc_html_e( 'Include only specified term IDs', 'fe-ai-search' ); ?>
															</label>
															<label>
																<input type="radio" name="<?php echo esc_attr( $tax_behavior_radio_name ); ?>" value="exclude_terms" <?php checked( $snippet_tax_behavior, 'exclude_terms' ); ?>>
																<?php esc_html_e( 'Exclude specified term IDs', 'fe-ai-search' ); ?>
															</label>
														</div>
														<input type="text" name="<?php echo esc_attr( $tax_term_ids_name ); ?>" value="<?php echo esc_attr( $snippet_tax_term_ids ); ?>" placeholder="<?php esc_attr_e( 'e.g. 3,7,12', 'fe-ai-search' ); ?>" class="regular-text">
														<p class="description">
															<?php esc_html_e( 'Comma-separated term IDs. Leave empty to include all terms.', 'fe-ai-search' ); ?>
														</p>
													</div>
												</td>
											</tr>
											<?php
										}
									}
									?>
								</tbody>
							</table>

							<hr>

							<div>
								<label>
									<input
										type="checkbox"
										name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][enable_custom_fields]"
										value="1"
										class="fe-ai-search-cf-toggle"
										data-target-input-id="<?php echo $snippet_field_input_id; ?>"
										<?php checked( $enable_custom_fields ); ?>
										<?php disabled( ! $is_pro ); ?>
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

								<div class="custom-field-input-wrapper">
									<label for="<?php echo $snippet_field_input_id; ?>">
										<strong><?php esc_html_e( 'Custom Fields Keys', 'fe-ai-search' ); ?></strong>
									</label>
									<textarea
										id="<?php echo $snippet_field_input_id; ?>"
										name="fe_ai_search_settings[sync][targets][<?php echo esc_attr( $post_type->name ); ?>][snippet_custom_fields]"
										rows="3"
										cols="60"
										class="large-text code"
										placeholder="field_name_1, field_name_2"
										<?php disabled( ! $is_pro ); ?>
									><?php echo esc_textarea( $snippet_custom_fields_value ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'Enter only the keys of custom fields you want to include in chunk data (content snippets), separated by commas or new lines.', 'fe-ai-search' ); ?>
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
				$vector_store = $this->options['vector']['store'] ?? 'mariadb';
				if ( 'qdrant' !== $vector_store ) {
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
						<?php
					endif;
				}
				?>

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
	 * Renders the UI for deleting all system logs.
	 *
	 * Placed under the "Delete Synced Data" control in the Advanced tab so that
	 * all destructive maintenance actions are grouped together.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function delete_system_logs_ui_field_html() {
		?>
		<p class="description">
			<?php esc_html_e( 'This will delete all system logs stored in the `{prefix}fe_ai_search_system_logs` table. Use this if logs have grown unexpectedly large or contain information you no longer wish to keep.', 'fe-ai-search' ); ?>
		</p>
		<button type="button" id="fe_ai_search_delete_system_logs_button" class="button button-secondary">
			<?php esc_html_e( 'Delete all system logs', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner"></span>
		<p id="fe_ai_search_delete_logs_status"></p>
		<?php
	}

	/**
	 * Renders the UI for deleting all conversation logs.
	 *
	 * Placed next to the system log delete control so that each log type can
	 * be purged independently.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function delete_conversation_logs_ui_field_html() {
		?>
		<p class="description">
			<?php esc_html_e( 'This will delete all conversation logs stored in the `{prefix}fe_ai_search_logs` table. Use this if conversations have grown unexpectedly large or contain information you no longer wish to keep.', 'fe-ai-search' ); ?>
		</p>
		<button type="button" id="fe_ai_search_delete_conversation_logs_button" class="button button-secondary">
			<?php esc_html_e( 'Delete all conversation logs', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner"></span>
		<p id="fe_ai_search_delete_conversation_logs_status"></p>
		<?php
	}

	/**
	 * Handles the AJAX request to delete all system logs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_delete_system_logs() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'You do not have permission to perform this action.', 'fe-ai-search' ),
				]
			);
		}

		\FEAISearch\Core\FE_AI_Search_Logger::clear_logs();

		wp_send_json_success( __( 'All system logs have been deleted.', 'fe-ai-search' ) );
	}

	/**
	 * Handles the AJAX request to delete all conversation logs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_delete_conversation_logs() {
		check_ajax_referer( 'fe_ai_search_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'You do not have permission to perform this action.', 'fe-ai-search' ),
				]
			);
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_ai_search_logs';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "TRUNCATE TABLE `{$table_name}`" );
		}

		wp_send_json_success( __( 'All conversation logs have been deleted.', 'fe-ai-search' ) );
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
			<div id="fe-ai-search-floating-display-accordion" class="fe-ai-search-accordion-wrapper">
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
	 * Renders the numeric input for log retention days.
	 *
	 * Controls how many days system logs and conversation logs are kept before
	 * being automatically deleted by the daily log rotation cron.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function log_retention_days_field_html() {
		$advanced_options = $this->options['advanced'] ?? [];
		$days             = isset( $advanced_options['log_retention_days'] ) ? (int) $advanced_options['log_retention_days'] : 30;
		if ( $days < 0 ) {
			$days = 30;
		}
		?>
		<input
			type="number"
			name="fe_ai_search_settings[advanced][log_retention_days]"
			value="<?php echo esc_attr( $days ); ?>"
			class="small-text"
			min="1"
		/>
		<p class="description">
			<?php
			esc_html_e(
				'Number of days to keep system logs and conversation logs. Older entries will be deleted automatically by the daily log rotation. Leave blank or set to -1 to disable automatic deletion.',
				'fe-ai-search'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the HTML for the Japanese tokenizer settings section.
	 *
	 * This method outputs form fields for selecting the Japanese tokenization
	 * engine (TinySegmenter or Yahoo! Japanese MA API) and configuring the
	 * Yahoo! App ID. It only displays for Japanese locale sites and includes
	 * information about wp-config.php constant overrides.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function japanese_tokenizer_field_html() {
		$locale = get_locale();
		if ( 'ja' !== $locale && 'ja_JP' !== $locale ) {
			return;
		}
		$tokenizer_options  = $this->options['tokenizer']['ja'] ?? [];
		$engine             = $tokenizer_options['engine'] ?? 'tinysegmenter';
		$encrypted_yahoo_id = $tokenizer_options['yahoo_id'] ?? '';
		$yahoo_id           = FE_AI_Search_Encryption_Helper::decrypt( $encrypted_yahoo_id );

		$engines = [
			'tinysegmenter' => __( 'Built-in (TinySegmenter)', 'fe-ai-search' ),
			'yahoo_ma'      => __( 'Yahoo! Japanese MA API', 'fe-ai-search' ),
		];

		$has_constant = defined( 'FE_AI_SEARCH_YAHOO_APP_ID' );
		$const_id     = $has_constant ? FE_AI_SEARCH_YAHOO_APP_ID : '';
		?>
		<fieldset>
			<select id="fe_ai_search_japanese_tokenizer_engine" name="fe_ai_search_settings[tokenizer][ja][engine]">
				<?php foreach ( $engines as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $engine, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php esc_html_e( 'Select the tokenizer used for Japanese keyword extraction in indexing and search.', 'fe-ai-search' ); ?><br>
				<?php echo wp_kses_post( __( '<strong>Note:</strong> Not used when using vector database.', 'fe-ai-search' ) ); ?>
			</p>

			<div style="margin-top:1em;">
				<label for="fe_ai_search_yahoo_app_id">
					<?php esc_html_e( 'Yahoo! App ID (optional)', 'fe-ai-search' ); ?>
				</label>
				<input
					id="fe_ai_search_yahoo_app_id"
					type="password"
					name="fe_ai_search_settings[tokenizer][ja][yahoo_id]"
					value="<?php echo esc_attr( $yahoo_id ); ?>"
					class="regular-text"
				>
				<p class="description">
					<?php esc_html_e( 'Used when the Yahoo! Japanese MA API is selected. This value is stored in the plugin settings.', 'fe-ai-search' ); ?><br>
					<?php if ( $has_constant ) : ?>
						<?php
							printf(
								/* translators: 1: constant name, 2: truncated value */
								esc_html__( '%1$s is defined in wp-config.php and will be used with priority over this field. (Current constant starts with: %2$s)', 'fe-ai-search' ),
								'FE_AI_SEARCH_YAHOO_APP_ID',
								esc_html( mb_substr( $const_id, 0, 8 ) )
							);
						?>
					<?php else : ?>
						<?php esc_html_e( 'If you also define FE_AI_SEARCH_YAHOO_APP_ID in wp-config.php, that constant will override this field.', 'fe-ai-search' ); ?>
					<?php endif; ?>
				</p>
			</div>
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
			<?php
			esc_html_e(
				'Instead of automatically displaying the floating chat window based on the conditions above, you can also embed the chat anywhere on your site using the shortcode. To do so, paste the following shortcode into the content of the page where you want to display the chat.',
				'fe-ai-search'
			);
			?>
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
			<?php echo $this->license_alert_icon; ?>
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
	 * Renders the HTML for the "Chat Text & Colors" settings section.
	 *
	 * This method outputs input fields for chat UI customization including
	 * window title, greeting message, placeholder text, submit button text,
	 * and color pickers for key color, background color, and text color.
	 * It also includes the gradient toggle option for visual styling.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_text_color_field_html() {
		$display_options = $this->options['display']['text'] ?? [];
		$ui_options      = $this->options['display']['ui'] ?? [];

		// Shared defaults for chat text (used by both settings placeholders and frontend UI).
		$defaults = \FEAISearch\Core\FE_AI_Search_Defaults::get_display_text_defaults();
		// Add legacy color defaults for this settings section.
		$defaults += [
			'key_color'        => '#0073aa',
			'background_color' => '#f5f5f5',
			'text_color'       => '#111111',
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

		<div id="fe-ai-search-color-picker">
			<div class="color-picker-box">
				<div class="color-picker-box-left">
					<input
						type="hidden"
						id="fe_ai_search_key_color"
						name="fe_ai_search_settings[display][ui][key_color]"
						value="<?php echo esc_attr( $key_color ); ?>"
					>
					<div
						class="fe-ai-search-color-picker"
						data-target-input="fe_ai_search_key_color"
						data-default-color="<?php echo esc_attr( $key_color ); ?>"
					></div>
				</div>
				<div class="color-picker-box-right">
					<label for="fe_ai_search_key_color"><?php esc_html_e( 'Key Color', 'fe-ai-search' ); ?></label>
					<span class="description"><?php esc_html_e( 'Select the basic colors for chat bubbles and user speech balloons.', 'fe-ai-search' ); ?></span>
				</div>
			</div>

			<hr>

			<div class="color-picker-box">
				<div class="color-picker-box-left">
					<input
						type="hidden"
						id="fe_ai_search_background_color"
						name="fe_ai_search_settings[display][ui][background_color]"
						value="<?php echo esc_attr( $background_color ); ?>"
					>
					<div
						class="fe-ai-search-color-picker"
						data-target-input="fe_ai_search_background_color"
						data-default-color="<?php echo esc_attr( $background_color ); ?>"
					></div>
				</div>
				<div class="color-picker-box-right">
					<label for="fe_ai_search_background_color"><?php esc_html_e( 'Chat window background color', 'fe-ai-search' ); ?></label>
					<span class="description"><?php esc_html_e( 'Background color for the chat window and message area.', 'fe-ai-search' ); ?></span>
				</div>
			</div>

			<hr>

			<div class="color-picker-box">
				<div class="color-picker-box-left">
					<input
						type="hidden"
						id="fe_ai_search_text_color"
						name="fe_ai_search_settings[display][ui][text_color]"
						value="<?php echo esc_attr( $text_color ); ?>"
					>
					<div
						class="fe-ai-search-color-picker"
						data-target-input="fe_ai_search_text_color"
						data-default-color="<?php echo esc_attr( $text_color ); ?>"
					></div>
				</div>
				<div class="color-picker-box-right">
					<label for="fe_ai_search_text_color"><?php esc_html_e( 'Base text color', 'fe-ai-search' ); ?></label>
					<span class="description"><?php esc_html_e( 'Default text color used for chat content and labels.', 'fe-ai-search' ); ?></span>
				</div>
			</div>

			<hr>

			<div class="color-picker-text">
				<label>
					<input
						type="checkbox"
						name="fe_ai_search_settings[display][ui][use_gradient]"
						value="1"
						<?php checked( (bool) $use_gradient ); ?>
					>
					<?php esc_html_e( 'Display chat background and key color with gradients', 'fe-ai-search' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'When unchecked, the chat UI will use flat colors without gradients.', 'fe-ai-search' ); ?></span>
			</div>

		</div>

		<?php
	}

	/**
	 * Renders the HTML for the Advanced Display settings section.
	 *
	 * This method outputs checkboxes for enabling/disabling default plugin
	 * CSS and JavaScript assets. It allows developers to replace default
	 * assets with custom implementations for full control over the chat UI.
	 *
	 * @since 1.0.0
	 * @return void
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
			<?php echo wp_kses_post( __( 'If you want to sync only certain posts, enter the post IDs separated by commas (e.g. 10, 25, 103).<br><strong>Note:</strong> When you enter post IDs here, only those posts are prioritized as sync targets. The per-post-type “Sync Targets” settings (which metadata to include, etc.) are still applied.', 'fe-ai-search' ) ); ?>
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
			$prompt = \FESearchAI\Ajax\FE_Search_AI_Chat_Handler::get_default_system_prompt();
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

		// Provider Tab - Encrypt API keys before saving
		$new_input['provider']['openai_key']    = FE_AI_Search_Encryption_Helper::encrypt( sanitize_text_field( $input['provider']['openai_key'] ?? '' ) );
		$new_input['provider']['google_key']    = FE_AI_Search_Encryption_Helper::encrypt( sanitize_text_field( $input['provider']['google_key'] ?? '' ) );
		$new_input['provider']['anthropic_key'] = FE_AI_Search_Encryption_Helper::encrypt( sanitize_text_field( $input['provider']['anthropic_key'] ?? '' ) );
		$new_input['provider']['embedding']     = sanitize_key( $input['provider']['embedding'] ?? 'openai' );
		$new_input['provider']['chat']          = sanitize_key( $input['provider']['chat'] ?? 'openai' );

		// OpenAI-Compatible key (stored in Free version settings)
		$new_input['provider']['openai_compatible_key'] = FE_AI_Search_Encryption_Helper::encrypt( sanitize_text_field( $input['provider']['openai_compatible_key'] ?? '' ) );

		// Prompt Tab
		$new_input['prompt']['site_name']     = sanitize_text_field( $input['prompt']['site_name'] ?? '' );
		$new_input['prompt']['site_purpose']  = sanitize_textarea_field( $input['prompt']['site_purpose'] ?? '' );
		$new_input['prompt']['system_prompt'] = sanitize_textarea_field( $input['prompt']['system_prompt'] ?? '' );

		// Sync Tab
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

		// Vector Store / Qdrant (Free)
		$existing_vector = $new_input['vector'] ?? [];
		if ( ! is_array( $existing_vector ) ) {
			$existing_vector = [];
		}
		$vector_input = $input['vector'] ?? [];
		if ( ! is_array( $vector_input ) ) {
			$vector_input = [];
		}
		$store = $vector_input['store'] ?? ( $existing_vector['store'] ?? 'mariadb' );
		if ( ! in_array( $store, [ 'mariadb', 'qdrant' ], true ) ) {
			$store = 'mariadb';
		}
		$new_input['vector']['store'] = $store;

		$qdrant_existing = $existing_vector['qdrant'] ?? [];
		if ( ! is_array( $qdrant_existing ) ) {
			$qdrant_existing = [];
		}
		$qdrant_input = $vector_input['qdrant'] ?? [];
		if ( ! is_array( $qdrant_input ) ) {
			$qdrant_input = [];
		}
		$new_input['vector']['qdrant']['endpoint']   = esc_url_raw( $qdrant_input['endpoint'] ?? ( $qdrant_existing['endpoint'] ?? '' ) );
		$new_input['vector']['qdrant']['collection'] = sanitize_text_field( $qdrant_input['collection'] ?? ( $qdrant_existing['collection'] ?? '' ) );
		$existing_api_key                            = $qdrant_existing['api_key'] ?? '';
		$new_api_key                                 = $qdrant_input['api_key'] ?? '';
		if ( '' === trim( (string) $new_api_key ) && '' !== $existing_api_key ) {
			$new_input['vector']['qdrant']['api_key'] = $existing_api_key;
		} else {
			$new_input['vector']['qdrant']['api_key'] = FE_AI_Search_Encryption_Helper::encrypt( sanitize_text_field( $new_api_key ) );
		}

		// Display Tab
		$new_input['display']['ui']       = $this->sanitize_display_ui( $input['display']['ui'] ?? [] );
		$new_input['display']['text']     = $this->sanitize_display_text( $input['display']['text'] ?? [] );
		$new_input['display']['links']    = $this->sanitize_display_links( $input['display']['links'] ?? [] );
		$new_input['display']['floating'] = $this->sanitize_display_floating( $input['display']['floating'] ?? [] );

		// Tokenizer (Japanese)
		$tokenizer_input = $input['tokenizer']['ja'] ?? [];
		if ( ! is_array( $tokenizer_input ) ) {
			$tokenizer_input = [];
		}
		$engine = sanitize_key( $tokenizer_input['engine'] ?? 'tinysegmenter' );
		if ( ! in_array( $engine, [ 'tinysegmenter', 'yahoo_ma' ], true ) ) {
			$engine = 'tinysegmenter';
		}
		$new_input['tokenizer']['ja']['engine']   = $engine;
		$new_input['tokenizer']['ja']['yahoo_id'] = FE_AI_Search_Encryption_Helper::encrypt( sanitize_text_field( $tokenizer_input['yahoo_id'] ?? '' ) );

		// Data Tab
		$new_input['advanced']['delete_on_uninstall'] = ! empty( $input['advanced']['delete_on_uninstall'] );
		$new_input['advanced']['debug_mode']          = ! empty( $input['advanced']['debug_mode'] );

		// License data is managed by a dedicated option (fe_ai_search_license).
		// DB version is handled by the activator, not here.
		$new_input['advanced']['db_version'] = $this->options['advanced']['db_version'] ?? '1.0';

		// Preserve sync status/settings
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
			$new_input[ $pt_name ]['enable_custom_fields'] = ! empty( $input[ $pt_name ]['enable_custom_fields'] );

			// Snippet flags control which metadata is embedded into each chunk.
			$new_input[ $pt_name ]['snippet_include_title']   = ! empty( $input[ $pt_name ]['snippet_include_title'] );
			$new_input[ $pt_name ]['snippet_include_content'] = ! empty( $input[ $pt_name ]['snippet_include_content'] );
			$new_input[ $pt_name ]['snippet_include_date']    = ! empty( $input[ $pt_name ]['snippet_include_date'] );
			$new_input[ $pt_name ]['snippet_include_author']  = ! empty( $input[ $pt_name ]['snippet_include_author'] );

			// Custom Fields (Pro) - only snippet_custom_fields remains
			if ( isset( $input[ $pt_name ]['snippet_custom_fields'] ) ) {
				$new_input[ $pt_name ]['snippet_custom_fields'] = sanitize_textarea_field( $input[ $pt_name ]['snippet_custom_fields'] );
			}

			// Taxonomies: new per-taxonomy enabled/behavior/term_ids structure
			if ( isset( $input[ $pt_name ]['snippet_taxonomies'] ) && is_array( $input[ $pt_name ]['snippet_taxonomies'] ) ) {
				$new_input[ $pt_name ]['snippet_taxonomies'] = [];
				foreach ( $input[ $pt_name ]['snippet_taxonomies'] as $tax_name => $tax_config ) {
					if ( ! is_array( $tax_config ) ) {
						continue;
					}
					$enabled  = ! empty( $tax_config['enabled'] );
					$behavior = in_array( $tax_config['behavior'] ?? '', [ 'include_terms', 'exclude_terms' ], true ) ? $tax_config['behavior'] : 'include_terms';
					$term_ids = sanitize_text_field( $tax_config['term_ids'] ?? '' );
					$new_input[ $pt_name ]['snippet_taxonomies'][ $tax_name ] = [
						'enabled'  => $enabled,
						'behavior' => $behavior,
						'term_ids' => $term_ids,
					];
				}
			} else {
				$new_input[ $pt_name ]['snippet_taxonomies'] = [];
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
	 * Renders the HTML for the "Interaction" settings section.
	 *
	 * This method outputs the form fields for animation speed control and
	 * send key configuration. It includes a range slider for animation speed
	 * (1-10) and a dropdown for send key behavior (Enter/Shift+Enter/Cmd+Enter).
	 *
	 * @since 1.0.0
	 * @return void
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
	 * Renders the HTML for the "Legal Links" settings section.
	 *
	 * This method outputs the form fields for selecting Terms of Service
	 * and Privacy Policy pages using WordPress page dropdowns. It includes
	 * descriptions for each field and fallback behavior for privacy settings.
	 *
	 * @since 1.0.0
	 * @return void
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
