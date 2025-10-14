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
 */

namespace FEAISearch\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The main settings page UI controller for the plugin.
 *
 * This class encapsulates all the logic for initializing and displaying the
 * various settings sections and fields, including API keys, synchronization options,
 * and display preferences.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Settings {

	private $is_license_active = '';
	private $license_alert_icon = '';

	public function __construct() {

		$this->is_license_active = ( 'active' === get_option( 'feas_ai_pro_license_status', 'inactive' ) );

		if ( class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) {
			$this->license_alert_icon = '<a href="' . admin_url( 'admin.php' )
			. '?page=fe-ai-search#tab-license">'
			. '<span class="dashicons dashicons-warning"></span></a>';
		}

		add_action( 'admin_init', array( $this, 'settings_init' ) );
	}

	/**
	 * A static method for rendering the settings page HTML.
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="#tab-api" class="nav-tab"><?php esc_html_e( 'API Settings', 'fe-ai-search' ); ?></a>
				<a href="#tab-sync" class="nav-tab"><?php esc_html_e( 'Sync', 'fe-ai-search' ); ?></a>
				<a href="#tab-display" class="nav-tab"><?php esc_html_e( 'Display', 'fe-ai-search' ); ?></a>
				<a href="#tab-prompt" class="nav-tab"><?php esc_html_e( 'Prompt', 'fe-ai-search' ); ?></a>
				<a href="#tab-data" class="nav-tab"><?php esc_html_e( 'Data', 'fe-ai-search' ); ?></a>
				<?php
				/**
				 * Fires within the settings page's tab wrapper to add custom navigation tabs.
				 *
				 * This action allows other plugins or themes (like the Pro add-on) to
				 * add new tabs to the main settings page navigation.
				 *
				 * @since 1.0.0
				 */
				do_action( 'feas_ai_settings_tabs' );
				?>
			</h2>

			<form action="options.php" method="post">
				<?php settings_fields( 'feas-ai-settings' ); ?>

				<div id="tab-api" class="tab-content">
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'feas_ai_api_section' ); ?>
					</table>
					<?php do_action( 'feas_ai_after_api_settings_fields' ); ?>
				</div>

				<div id="tab-sync" class="tab-content">
					<?php do_settings_sections( 'feas_ai_sync_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'feas_ai_sync_section' ); ?>
					</table>
					<?php do_action( 'feas_ai_after_sync_settings_fields' ); ?>
				</div>

				<div id="tab-display" class="tab-content">
					<?php do_settings_sections( 'feas_ai_display_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'feas_ai_display_section' ); ?>
					</table>
					<?php do_action( 'feas_ai_after_display_settings_fields' ); ?>
				</div>

				<div id="tab-prompt" class="tab-content">
					<?php do_settings_sections( 'feas_ai_prompt_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'feas_ai_prompt_section' ); ?>
					</table>
					<?php do_action( 'feas_ai_after_prompt_settings_fields' ); ?>
				</div>

				<div id="tab-data" class="tab-content">
					<?php do_settings_sections( 'feas_ai_data_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'feas_ai_data_section' ); ?>
					</table>
					<?php do_action( 'feas_ai_after_data_settings_fields' ); ?>
					<?php do_settings_sections( 'feas_ai_debug_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields('fe-ai-search', 'feas_ai_debug_section'); ?>
					</table>
				</div>

				<?php
				/**
				 * Fires within the main settings form to add custom tab content panels.
				 *
				 * This action is intended to be used in conjunction with the
				 * 'feas_ai_settings_tabs' action. It allows other plugins to render the
				 * content (the <div id="tab-...">) for the custom tabs they have added.
				 *
				 * @since 1.0.0
				 */
				do_action( 'feas_ai_settings_tabs_content' );
				?>

				<?php submit_button( __( 'Save Settings', 'fe-ai-search' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Initialize the Settings API
	 */
	public function settings_init() {
		$settings_group = 'feas-ai-settings';
		$page_slug      = 'fe-ai-search';

		// API Settings Section
		add_settings_section(
			'feas_ai_api_section',
			__( 'API Settings', 'fe-ai-search' ),
			null,
			$page_slug
		);

		register_setting( $settings_group, 'feas_ai_chat_provider' );
		register_setting( $settings_group, 'feas_ai_embedding_provider' );
		register_setting( $settings_group, 'feas_ai_openai_api_key' );
		register_setting( $settings_group, 'feas_ai_google_api_key' );
		register_setting( $settings_group, 'feas_ai_anthropic_api_key' );

		add_settings_field(
			'feas_ai_chat_provider',
			__( 'Answer generation model', 'fe-ai-search' ),
			array( $this, 'chat_provider_field_html' ),
			$page_slug,
			'feas_ai_api_section'
		);
		add_settings_field(
			'feas_ai_embedding_provider',
			__( 'Vectorization Model', 'fe-ai-search' ),
			array( $this, 'embedding_provider_field_html' ),
			$page_slug,
			'feas_ai_api_section'
		);
		add_settings_field(
			'feas_ai_openai_api_key',
			__( 'OpenAI API Key', 'fe-ai-search' ),
			array( $this, 'openai_api_key_field_html' ),
			$page_slug,
			'feas_ai_api_section'
		);
		add_settings_field(
			'feas_ai_google_api_key',
			__( 'Google Cloud API Key', 'fe-ai-search' ),
			array( $this, 'google_api_key_field_html' ),
			$page_slug,
			'feas_ai_api_section'
		);
		add_settings_field(
			'feas_ai_anthropic_api_key',
			__( 'Anthropic (Claude) API Key', 'fe-ai-search' ),
			array( $this, 'anthropic_api_key_field_html' ),
			$page_slug,
			'feas_ai_api_section'
		);

		/**
		 *
		 *  Synchronization Settings Section
		 *
		 */
		add_settings_section(
			'feas_ai_sync_section',
			__( 'Sync Settings', 'fe-ai-search' ),
			null,
			$page_slug
		);

		register_setting(
			$settings_group,
			'feas_ai_sync_options',
			[ 'sanitize_callback' => [ $this, 'sanitize_sync_options' ] ]
		);
		register_setting(
			$settings_group,
			'feas_ai_include_post_ids',
			[ 'sanitize_callback' => [ $this, 'sanitize_numeric_string' ] ]
		);
		register_setting(
			$settings_group,
			'feas_ai_exclude_post_ids',
			[ 'sanitize_callback' => [ $this, 'sanitize_numeric_string' ] ]
		);
		register_setting(
			$settings_group,
			'feas_ai_sync_limit',
			[ 'sanitize_callback' => [ $this, 'sanitize_numeric_string' ] ]
		);
		register_setting( $settings_group, 'feas_ai_batch_size' );

		add_settings_field(
			'feas_ai_sync_options',
			__( 'What is synchronized and what is included?', 'fe-ai-search' ),
			[ $this, 'sync_options_field_html' ],
			$page_slug,
			'feas_ai_sync_section'
		);

		add_settings_field(
			'feas_ai_include_post_ids',
			__( 'Post IDs to include', 'fe-ai-search' ),
			[ $this, 'include_post_ids_field_html' ],
			$page_slug,
			'feas_ai_sync_section'
		);
		add_settings_field(
			'feas_ai_exclude_post_ids',
			__( 'Post IDs to exclude', 'fe-ai-search' ),
			[ $this, 'exclude_post_ids_field_html' ],
			$page_slug,
			'feas_ai_sync_section'
		);
		add_settings_field(
			'feas_ai_sync_limit',
			__( 'Maximum number of posts to sync', 'fe-ai-search' ),
			[ $this, 'sync_limit_field_html' ],
			$page_slug,
			'feas_ai_sync_section'
		);
		add_settings_field(
			'feas_ai_batch_size',
			'同期バッチサイズ',
			[ $this, 'batch_size_field_html' ],
			$page_slug,
			'feas_ai_sync_section'
		);
		add_settings_field(
			'feas_ai_sync_ui',
			__( 'Manual Sync', 'fe-ai-search' ),
			[ $this, 'sync_ui_field_html' ],
			$page_slug,
			'feas_ai_sync_section'
		);

		/**
		 *
		 *  Prompt Settings Section
		 *
		 */
		add_settings_section(
			'feas_ai_prompt_section',
			__( 'System Prompt', 'fe-ai-search' ),
			null,
			$page_slug
		);

		register_setting( $settings_group, 'feas_ai_system_prompt' );

		add_settings_field(
			'feas_ai_system_prompt',
			__( 'Base System Prompt', 'fe-ai-search' ),
			[ $this, 'system_prompt_field_html' ],
			$page_slug,
			'feas_ai_prompt_section'
		);

		/**
		 *
		 *  Display Settings Section
		 *
		 */
		add_settings_section(
			'feas_ai_display_section',
			__( 'Display settings', 'fe-ai-search' ),
			null,
			$page_slug
		);

		register_setting(
			$settings_group,
			'feas_ai_display_options',
			[ 'sanitize_callback' => [ $this, 'sanitize_display_options' ] ]
		);

		add_settings_field(
			'feas_ai_display_floating',
			__( 'Floating Window', 'fe-ai-search' ),
			array( $this, 'display_floating_field_html' ),
			$page_slug,
			'feas_ai_display_section'
		);
		add_settings_field(
			'feas_ai_display_fullscreen',
			__( 'Full Screen Mode', 'fe-ai-search' ),
			array( $this, 'display_fullscreen_field_html' ),
			$page_slug,
			'feas_ai_display_section'
		);
		add_settings_field(
			'feas_ai_display_embed',
			__( 'Embedding Mode', 'fe-ai-search' ),
			array( $this, 'display_embed_field_html' ),
			$page_slug,
			'feas_ai_display_section'
		);
		add_settings_field(
			'feas_ai_display_chat_text',
			 __( 'Chat Message Settings', 'fe-ai-search' ),
			 array( $this, 'display_chat_text_field_html' ),
			 $page_slug,
			 'feas_ai_display_section'
		 );
		add_settings_field(
			'feas_ai_display_advanced',
			__( 'Advanced Settings', 'fe-ai-search' ),
			array( $this, 'display_advanced_field_html' ),
			$page_slug,
			'feas_ai_display_section'
		);

		/**
		 *
		 *  Data Management Section
		 *
		 */
		add_settings_section(
			'feas_ai_data_section',
			__( 'Data Management', 'fe-ai-search' ),
			null,
			$page_slug
		);

		register_setting( $settings_group, 'feas_ai_delete_on_uninstall' );

		add_settings_field(
			'feas_ai_delete_vectors_ui',
			__( 'Deleting Synced Data', 'fe-ai-search' ),
			[ $this, 'delete_vectors_ui_field_html' ],
			$page_slug,
			'feas_ai_data_section'
		);
		add_settings_field(
			'feas_ai_delete_on_uninstall',
			__( 'Uninstallation settings', 'fe-ai-search' ),
			[ $this, 'delete_on_uninstall_field_html' ],
			$page_slug,
			'feas_ai_data_section'
		);

		// --- Debugging Section ---
		add_settings_section(
			'feas_ai_debug_section',
			__( 'Debugging', 'fe-ai-search' ),
			null,
			$page_slug
		);

		register_setting( $settings_group, 'feas_ai_debug_mode_enabled' );

		add_settings_field(
			'feas_ai_debug_mode_enabled',
			__( 'Enable Debug Mode', 'fe-ai-search' ),
			[ $this, 'debug_mode_field_html' ],
			$page_slug,
			'feas_ai_debug_section'
		);
	}

	/**
	 * Determine whether the Pro version add-on is enabled
	 * @return bool
	 */
	public function chat_provider_field_html() {
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );

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
		$providers = apply_filters( 'feas_ai_chat_providers', $providers );

		?>
		<select name="feas_ai_chat_provider">
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
		$provider = get_option( 'feas_ai_embedding_provider', 'openai' );

		$providers = [
			'openai'    => 'OpenAI (text-embedding-3)',
			'google'    => 'Google (text-embedding-004)',
			'anthropic' => 'Anthropic (Claude) - (Not available)',
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
		$providers = apply_filters( 'feas_ai_embedding_providers', $providers );

		?>
		<select name="feas_ai_embedding_provider">
			<?php foreach ( $providers as $key => $name ) : ?>
				<option
					value="<?php echo esc_attr( $key ); ?>" <?php selected( $provider, $key ); ?>
					<?php if ( 'anthropic' === $key ) echo 'disabled="disabled"'; ?>>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select an AI that will vectorize (convert) your site content into a format that AI can understand.', 'fe-ai-search' );
			?>
		</p>
		<?php
	}

	public function openai_api_key_field_html() {
		$api_key = get_option( 'feas_ai_openai_api_key' );

		$model_to_display = 'gpt-4o-mini';
		if ( class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) {
			$model_to_display = get_option( 'feas_ai_openai_model', $model_to_display );
		}
		?>
		<input
			type="password"
			id="feas_ai_openai_api_key"
			name="feas_ai_openai_api_key"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
		>
		<button type="button" class="button button-secondary feas-ai-test-api" data-provider="openai">
			<?php esc_html_e( 'Connection Test', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<span class="feas-ai-api-status"></span>
		<p class="description">
			<?php esc_html_e( 'Enter your OpenAI API key.', 'fe-ai-search' ); ?>
		</p>

		<div style="margin-top: 10px;">
			<p style="margin: 0;">
				<strong><?php esc_html_e( 'Model', 'fe-ai-search' ); ?>:</strong> <?php echo esc_html( $model_to_display ); ?>
				<?php if ( class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) : ?>
					<div>
						<a href="#tab-pro-models" class="feas-ai-change-model-link">
							<?php esc_html_e( 'Change Model', 'fe-ai-search' ); ?> &raquo;
						</a> <?php echo $this->license_alert_icon; ?>
					</div>
				<?php else: ?>
					<div class="description">
						<?php
						printf(
							/* translators: %s: Link to the Pro version. */
							wp_kses_post( __( 'Model selection is available in the %s version.', 'fe-ai-search' ) ),
							sprintf(
								'<a href="%s" target="_blank">%s</a>',
								esc_url( FEAS_AI_PRO_URL ),
								esc_html__( 'Pro', 'fe-ai-search' )
							)
						);
						?>
					</div>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public function google_api_key_field_html() {
		$api_key = get_option( 'feas_ai_google_api_key' );

		$model_to_display = 'gemini-2.5-flash-lite';
		if ( class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) {
			$model_to_display = get_option( 'feas_ai_google_model', $model_to_display );
		}
		?>
		<input
			type="password"
			id="feas_ai_google_api_key"
			name="feas_ai_google_api_key"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
		>
		<button type="button" class="button button-secondary feas-ai-test-api" data-provider="google">
			<?php esc_html_e( 'Connection Test', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<span class="feas-ai-api-status"></span>
		<p class="description">
			<?php esc_html_e( 'Enter your Google Cloud API key.', 'fe-ai-search' ); ?>
		</p>

		<div style="margin-top: 10px;">
			<p style="margin: 0;">
				<strong><?php esc_html_e( 'Model', 'fe-ai-search' ); ?>:</strong> <?php echo esc_html( $model_to_display ); ?>
				<?php if ( class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) : ?>
					<div>
						<a href="#tab-pro-models" class="feas-ai-change-model-link">
							<?php esc_html_e( 'Change Model', 'fe-ai-search' ); ?> &raquo;
						</a> <?php echo $this->license_alert_icon; ?>
					</div>
				<?php else: ?>
					<div class="description">
						<?php
						printf(
							/* translators: %s: Link to the Pro version. */
							wp_kses_post( __( 'Model selection is available in the %s version.', 'fe-ai-search' ) ),
							sprintf(
								'<a href="%s" target="_blank">%s</a>',
								esc_url( FEAS_AI_PRO_URL ),
								esc_html__( 'Pro', 'fe-ai-search' )
							)
						);
						?>
					</div>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public function anthropic_api_key_field_html() {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );

		$model_to_display = 'claude-3-5-haiku';
		if ( class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) {
			$model_to_display = get_option( 'feas_ai_anthropic_model', $model_to_display );
		}
		?>
		<input
			type="password"
			id="feas_ai_anthropic_api_key"
			name="feas_ai_anthropic_api_key"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
		>
		<button type="button" class="button button-secondary feas-ai-test-api" data-provider="anthropic">
			<?php esc_html_e( 'Connection Test', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<span class="feas-ai-api-status"></span>
		<p class="description">
			<?php esc_html_e( 'Enter your Anthropic (Claude) API key.', 'fe-ai-search' ); ?>
		</p>

		<div style="margin-top: 10px;">
			<p style="margin: 0;">
				<strong><?php esc_html_e( 'Model', 'fe-ai-search' ); ?>:</strong> <?php echo esc_html( $model_to_display ); ?>
				<?php if ( class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) : ?>
					<div>
						<a href="#tab-pro-models" class="feas-ai-change-model-link">
							<?php esc_html_e( 'Change Model', 'fe-ai-search' ); ?> &raquo;
						</a> <?php echo $this->license_alert_icon; ?>
					</div>
				<?php else: ?>
					<div class="description">
						<?php
						printf(
							/* translators: %s: Link to the Pro version. */
							wp_kses_post( __( 'Model selection is available in the %s version.', 'fe-ai-search' ) ),
							sprintf(
								'<a href="%s" target="_blank">%s</a>',
								esc_url( FEAS_AI_PRO_URL ),
								esc_html__( 'Pro', 'fe-ai-search' )
							)
						);
						?>
					</div>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}


	public function sync_options_field_html() {
		$options    = get_option( 'feas_ai_sync_options', [] );
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$excluded_post_types = ['attachment'];

		$string = __( 'Select the post types you want to include in your AI search and the metadata you want to include in chunks for each post type.', 'fe-ai-search' );
		echo '<p class="description">' . wp_kses_post( $string ) . '</p>';
		echo '<div id="feas-ai-sync-options-accordion" class="feas-ai-accordion-wrapper">';

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $excluded_post_types, true ) ) {
				continue;
			}

			// Get saved options for this post type, using '??' to provide safe defaults.
			$pt_options      = $options['post_types'][ $post_type->name ] ?? [];
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
							name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][enabled]"
							value="1"
							<?php checked( $is_enabled ); ?>
						>
						<?php echo esc_html( $post_type->label ); ?> (<?php echo esc_html( $post_type->name ); ?>)
					</label>
				</h4>
				<div class="accordion-content" style="<?php echo $is_enabled ? 'display: block;' : 'display: none;'; ?>">
					<fieldset>
						<label>
							<input type="checkbox" name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][include_title]" value="1" <?php checked( $include_title ); ?>>
							<?php esc_html_e( 'Include Post Title', 'fe-ai-search' ); ?>
						</label>
						<label>
							<input type="checkbox" name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][include_content]" value="1" <?php checked( $include_content ); ?>>
							<?php esc_html_e( 'Include Post Content', 'fe-ai-search' ); ?>
						</label>
						<label>
							<input type="checkbox" name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][include_date]" value="1" <?php checked( $include_date ); ?>>
							<?php esc_html_e( 'Include Post Date', 'fe-ai-search' ); ?>
						</label>
						<label>
							<input type="checkbox" name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][include_author]" value="1" <?php checked( $include_author ); ?>>
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
									<input type="checkbox" name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][taxonomies][]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( $is_checked ); ?>>
									<?php printf( esc_html__( 'Include %s', 'fe-ai-search' ), esc_html( $tax->label ) ); ?>
								</label>
								<?php
							}
						}
						?>

						<div>
							<label for="feas_ai_custom_fields_<?php echo esc_attr( $post_type->name ); ?>">
								<strong><?php esc_html_e( 'Include Custom Fields', 'fe-ai-search' ); ?></strong> <?php echo $this->is_license_active ? '' : $this->license_alert_icon; ?>
							</label>
							<input
								type="text"
								id="feas_ai_custom_fields_<?php echo esc_attr( $post_type->name ); ?>"
								name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][custom_fields]"
								value="<?php echo esc_attr( $pt_options['custom_fields'] ?? '' ); ?>"
								class="regular-text"
								placeholder="field_name_1, field_name_2"
								<?php disabled( ! $this->is_license_active ); ?>
							>
							<p class="description">
								<?php esc_html_e( 'Enter the keys of the custom fields you want to include, separated by commas.', 'fe-ai-search' ); ?>
							</p>
							<?php if ( ! class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ) ) : ?>
								<p class="description">
									<?php
									printf(
										wp_kses_post( __( 'This is a Pro feature. <a href="%s" target="_blank">Upgrade to Pro</a>', 'fe-ai-search' ) ),
										esc_url( FEAS_AI_PRO_URL )
									);
									?>
								</p>
							<?php endif; ?>
						</div>
					</fieldset>
				</div>
			</div>
			<?php
		}
		echo '</div>';
	}


	public function sync_ui_field_html() {
		global $wpdb;
		$vectors_table      = $wpdb->prefix . 'feas_ai_vectors';
		$indexed_post_count = $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM `{$vectors_table}`" );
		$last_sync          = get_option( 'feas_ai_last_sync_timestamp' );
		?>
		<div style="padding-top: 10px;">
			<div style="margin-bottom: 1em; padding: 10px; background-color: #f0f0f1; border: 1px solid #ddd;">
				<?php
				if ( $last_sync ) {
					printf(
						'<p style="margin: 0;"><strong>%s:</strong> %s</p>',
						esc_html__( 'Last Sync Date and Time', 'fe-ai-search' ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) )
					);
				}
				printf(
					'<p style="margin: 0;"><strong>%s:</strong> %s %s</p>',
					esc_html__( 'Number of indexed articles', 'fe-ai-search' ),
					esc_html( $indexed_post_count ),
					esc_html__( 'posts', 'fe-ai-search' )
				);
				?>
			</div>
			<div id="feas-ai-sync-wrapper">
				<button id="feas-ai-smart-sync" class="button button-primary" style="margin-left: 10px;">
					<?php esc_html_e( 'Sync Changes (Recommended)', 'fe-ai-search' ); // 更新を同期 (推奨) ?>
				</button>
				<button id="feas-ai-start-sync" class="button button-secondary">
					<?php esc_html_e( 'Rebuild Index', 'fe-ai-search' ); // インデックスを再構築 ?>
				</button>
				<div id="feas-ai-progress-container" style="display: none; margin-top: 10px;">
					<div id="feas-ai-progress-bar-wrapper" style="width: 100%; background-color: #ddd; border: 1px solid #ccc;">
						<div
							id="feas-ai-progress-bar"
							style="width: 0%; height: 24px; background-color: #0073aa; text-align: right; color: white; line-height: 24px; padding-right: 8px; box-sizing: border-box;"
						>0%</div>
					</div>
					<div id="feas-ai-sync-status" style="margin-top: 5px; min-height: 20px;">
						<span class="dashicons dashicons-update-alt spin" style="display: none; vertical-align: middle; margin-right: 5px;"></span>
						<span class="status-text"></span>
					</div>
				</div>
			</div>
			<p class="description">
				<?php esc_html_e( 'Prepare and index your data so that your posts and pages can be searched by AI.', 'fe-ai-search' ); ?>
			</p>
		</div>
		<?php
	}

	public function delete_vectors_ui_field_html() {
		?>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'This will delete all synced vector data and keyword indexes. AI search will no longer work until you sync again.', 'fe-ai-search' ); ?>
		</p>
		<button type="button" id="feas-ai-delete-vectors-button" class="button button-secondary">
			<?php esc_html_e( 'Delete all synced data', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<p id="feas-ai-delete-status" style="display: inline-block; margin-left: 10px;"></p>
		<?php
	}

	public function display_floating_field_html() {
		$options = get_option( 'feas_ai_display_options', [] );
		$defaults = [
			'enable_floating_mode' => '1',
			'display_on_pc'        => '1',
			'display_on_mobile'    => '1',
			'fullscreen_page_id'   => 0,
			'display_rules'        => [
				'show_on_front_page' => '1',
				'show_on_archives'   => '1',
				'show_on_search'     => '1',
				'post_types'         => ['post' => '1', 'page' => '1'],
				'include_ids'        => '',
				'exclude_ids'        => '',
			],
		];
		$options = wp_parse_args($options, $defaults);
		?>
		<fieldset>
			<p>
				<label>
					<input
						type="checkbox"
						name="feas_ai_display_options[enable_floating_mode]"
						value="1" <?php checked( $options['enable_floating_mode'] ); ?>
					>
					<strong><?php esc_html_e( 'Enable floating chat', 'fe-ai-search' ); ?></strong>
				</label>
			</p>

			<div>
				<p>
					<div class="option-header"><?php esc_html_e( 'Display device', 'fe-ai-search' ); ?>:</div>
					<label>
						<input
							type="checkbox"
							name="feas_ai_display_options[display_on_pc]"
							value="1" <?php checked( $options['display_on_pc'] ); ?>
						> <?php esc_html_e( 'PC', 'fe-ai-search' ); ?>
					</label>
					<label>
						<input
							type="checkbox"
							name="feas_ai_display_options[display_on_mobile]"
							value="1" <?php checked( $options['display_on_mobile'] ); ?>
						> <?php esc_html_e( 'Mobile', 'fe-ai-search' ); ?>
					</label>
				</p>

				<p>
					<div class="option-header">
						<?php esc_html_e( 'Conditions for Displaying the Page', 'fe-ai-search' ); ?>:
					</div>
					<label>
						<input
							type="checkbox"
							name="feas_ai_display_options[display_rules][show_on_front_page]"
							value="1" <?php checked( $options['display_rules']['show_on_front_page'] ); ?>
						> <?php esc_html_e( 'Home', 'fe-ai-search' ); ?>
					</label>
					<label>
						<input
							type="checkbox"
							name="feas_ai_display_options[display_rules][show_on_archives]"
							value="1" <?php checked( $options['display_rules']['show_on_archives'] ); ?>
						> <?php esc_html_e( 'Archive', 'fe-ai-search' ); ?>
					</label>
					<label>
						<input
							type="checkbox"
							name="feas_ai_display_options[display_rules][show_on_search]"
							value="1" <?php checked( $options['display_rules']['show_on_search'] ); ?>
						> <?php esc_html_e( 'Search Results', 'fe-ai-search' ); ?>
					</label>
				</p>

				<p>
					<div class="option-header">
						<?php esc_html_e( 'Display on individual pages of the following post types', 'fe-ai-search' ); ?>:
					</div>
					<?php
					$all_post_types = get_post_types( ['public' => true], 'objects' );
					foreach ($all_post_types as $pt) {
						if ('attachment' === $pt->name) continue;
						echo '<label style="margin-right: 15px;">
								<input
								type="checkbox"
								name="feas_ai_display_options[display_rules][post_types]['.esc_attr($pt->name).']"
								value="1" '
								.checked(!empty($options['display_rules']['post_types'][$pt->name]), true, false)
								.'> '.esc_html($pt->label)
								.'</label>';
					}
					?>
				</p>

				<div>
				<p>
					<div class="option-header"><?php esc_html_e( 'Overwrite rules by individual ID', 'fe-ai-search' ); ?>:</div>
					<label for="feas_ai_include_ids"><?php esc_html_e( 'Display only with these post IDs', 'fe-ai-search' ); ?>:</label>
					<input
						type="text"
						id="feas_ai_include_ids"
						name="feas_ai_display_options[display_rules][include_ids]"
						value="<?php echo esc_attr( $options['display_rules']['include_ids'] ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g.', 'fe-ai-search' ); ?> 10, 25, 103"
					>
					<p class="description"><?php esc_html_e( 'If you enter the ID here, other display rules will be ignored.', 'fe-ai-search' ); ?></p>
				</p>
				<p>
					<label for="feas_ai_exclude_ids"><?php esc_html_e( 'Do not display with these post IDs.', 'fe-ai-search' ); ?>:</label>
					<input
						type="text"
						id="feas_ai_exclude_ids"
						name="feas_ai_display_options[display_rules][exclude_ids]"
						value="<?php echo esc_attr( $options['display_rules']['exclude_ids'] ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g.', 'fe-ai-search' ); ?> 15, 30"
					>
				</p>
				</div>
			</div>
		</fieldset>
		<?php
	}

	// 2. 全画面モード設定
	public function display_fullscreen_field_html() {
		$options = get_option( 'feas_ai_display_options', [] );
		?>
		<fieldset>
			<label for="feas_fullscreen_page_id"><?php esc_html_e( 'Select Chat-Only Page', 'fe-ai-search' ); ?></label>
			<?php
			wp_dropdown_pages([
				'name'              => 'feas_ai_display_options[fullscreen_page_id]',
				'selected'          => $options['fullscreen_page_id'],
				'show_option_none'  => __( '— Don\'t use a dedicated page —', 'fe-ai-search' ),
				'option_none_value' => '0',
			]);
			?>
			<p class="description">
				<?php esc_html_e( 'When you access the selected static page here, the full-screen chat UI will be displayed directly.', 'fe-ai-search' ); ?>
			</p>
		</fieldset>

		<?php
	}

	// 3. 埋め込みモード設定
	public function display_embed_field_html() {
		?>
		<p class="description">
			<?php esc_html_e( 'Please paste the following shortcode into the content of the page where you want to display the chat.', 'fe-ai-search' ); ?>
		</p>
		<code>[fe-ai-search]</code>
		<?php
	}

	// 4. チャット文言設定
	public function display_chat_text_field_html() {
		$options = get_option( 'feas_ai_display_options', [] );

		// Animation Speed Settings
		$animation_speed = $options['animation_speed'] ?? 3;
		?>
		<p>
			<label for="feas_window_title"><?php esc_html_e( 'Window title', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="feas_window_title"
				name="feas_ai_display_options[window_title]"
				value="<?php echo esc_attr( $options['window_title'] ); ?>"
				class="regular-text"
			>
		</p>
		<p>
			<label for="feas_greeting_message"><?php esc_html_e( 'First greeting', 'fe-ai-search' ); ?></label>
			<textarea
				id="feas_greeting_message"
				name="feas_ai_display_options[greeting_message]"
				rows="3"
				class="large-text"
			><?php echo esc_textarea( $options['greeting_message'] ); ?></textarea>
		</p>
		<p>
			<label for="feas_placeholder_text"><?php esc_html_e( 'Input field placeholders', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="feas_placeholder_text"
				name="feas_ai_display_options[placeholder_text]"
				value="<?php echo esc_attr( $options['placeholder_text'] ); ?>"
				class="regular-text"
			>
		</p>
		<p>
			<label for="feas_submit_button_text"><?php esc_html_e( 'Submit button text', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="feas_submit_button_text"
				name="feas_ai_display_options[submit_button_text]"
				value="<?php echo esc_attr( $options['submit_button_text'] ); ?>"
				class="regular-text"
			>
		</p>
		<p>
			<label for="feas_key_color"><?php esc_html_e( 'Key Color', 'fe-ai-search' ); ?></label>
			<input
				type="text"
				id="feas_key_color"
				name="feas_ai_display_options[key_color]"
				value="<?php echo esc_attr( $options['key_color'] ?? '#0073aa' ); ?>"
				class="feas-ai-color-picker"
			>
			<p class="description"><?php esc_html_e( 'Select the basic colors for chat bubbles and user speech balloons.', 'fe-ai-search' ); ?></p>
		</p>

		<script>
			jQuery(document).ready(function($){
				$('.feas-ai-color-picker').wpColorPicker();
			});
		</script>

		<div style="display: flex; align-items: center; gap: 1em;">
			<span><?php esc_html_e( 'Smooth', 'fe-ai-search' ); ?></span>
			<input
				type="range"
				id="feas_ai_animation_speed_slider"
				name="feas_ai_display_options[animation_speed]"
				min="1"
				max="10"
				step="1"
				value="<?php echo esc_attr( $animation_speed ); ?>"
			>
			<span><?php esc_html_e( 'Fast', 'fe-ai-search' ); ?></span>
			<span id="feas_ai_animation_speed_value" style="font-weight: bold; min-width: 20px; text-align: right;">
				<?php echo esc_html( $animation_speed ); ?>
			</span>
		</div>
		<p class="description">
			<?php esc_html_e( 'Adjust the typing animation speed of the AI\'s response. This value represents the number of characters processed at once.', 'fe-ai-search' ); ?>
		</p>

		<p>
			<label for="feas_terms_page_id"><?php esc_html_e( 'Terms of Service Page', 'fe-ai-search' ); ?></label>
			<?php
			wp_dropdown_pages([
				'name'              => 'feas_ai_display_options[terms_page_id]',
				'selected'          => $options['terms_page_id'] ?? 0,
				'show_option_none'  => '— ' . __( 'Not selected', 'fe-ai-search' ) . ' —',
				'option_none_value' => '0',
			]);
			?>
		</p>

		<p>
			<label for="feas_privacy_page_id"><?php esc_html_e( 'Privacy Policy Page', 'fe-ai-search' ); ?></label>
			<?php
			wp_dropdown_pages([
				'name'              => 'feas_ai_display_options[privacy_page_id]',
				'selected'          => $options['privacy_page_id'] ?? 0,
				'show_option_none'  => '— ' . __( 'Not selected', 'fe-ai-search' ) . ' —',
				'option_none_value' => '0',
			]);
			?>
			<p class="description">
				<?php esc_html_e( 'If not selected, the page set in WordPress\'s standard privacy settings will be used.', 'fe-ai-search' ); ?>
			</p>
		</p>
		<?php
	}

	// Advanced Settings
	public function display_advanced_field_html() {
		$options = get_option( 'feas_ai_display_options', [] );

		// CSS設定
		$enable_css = $options['enable_css'] ?? true;
		?>
		<fieldset>
			<label>
				<input
					type="checkbox"
					name="feas_ai_display_options[enable_css]"
					value="1"
					<?php checked( $enable_css ); ?>
				/>
				<?php esc_html_e( 'Load CSS included with the plugin', 'fe-ai-search' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'If you want to design the chat UI with your own theme\'s CSS, uncheck this.', 'fe-ai-search' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function include_post_ids_field_html() {
		$ids = get_option( 'feas_ai_include_post_ids', '' );
		?>
		<input
			type="text"
			name="feas_ai_include_post_ids"
			value="<?php echo esc_attr( $ids ); ?>"
			class="regular-text"
		>
		<p class="description">
			<?php echo wp_kses_post( __( 'If you want to sync only certain posts, enter the post IDs separated by commas (e.g. 10, 25, 103).<br><strong>Note:</strong> If you enter any here, the "Post types to sync" setting will be ignored and only the posts with those IDs will be synced.', 'fe-ai-search' ) ); ?>
		</p>
		<?php
	}

	public function exclude_post_ids_field_html() {
		$ids = get_option( 'feas_ai_exclude_post_ids', '' );
		?>
		<input
			type="text"
			name="feas_ai_exclude_post_ids"
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

	public function sync_limit_field_html() {
		$limit = get_option( 'feas_ai_sync_limit', 100 );
		?>
		<input
			type="number"
			name="feas_ai_sync_limit"
			value="<?php echo esc_attr( $limit ); ?>"
			class="small-text"
		>
		<?php esc_html_e( 'posts', 'fe-ai-search' ); ?>
		<p class="description">
			<?php
			$string1 = __( 'Limits the number of posts to be synchronized to the specified number, starting from the most recent.', 'fe-ai-search' );
			$string2 = __( 'Set this if you are trying to synchronize on a trial basis or want to reduce API costs.<br><strong>0</strong> will synchronize all eligible posts.', 'fe-ai-search' );
			echo wp_kses_post( $string1 . $string2 );
			?>
		</p>
		<?php
	}

	public function delete_on_uninstall_field_html() {
		$option = get_option( 'feas_ai_delete_on_uninstall' );
		?>
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php esc_html_e( 'Uninstallation settings', 'fe-ai-search' ); ?></span>
			</legend>
			<label>
				<input
					type="checkbox"
					name="feas_ai_delete_on_uninstall"
					value="1"
					<?php checked( $option, '1' ); ?>
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

	public function sync_post_types_field_html() {
		$post_types = get_post_types( array('public' => true), 'objects' );
		$excluded_post_types = array('attachment');

		$saved_post_types = get_option( 'feas_ai_sync_post_types', array('post', 'page') );
		if ( ! is_array($saved_post_types) ) {
			$saved_post_types = array('post', 'page');
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
					name="feas_ai_sync_post_types[]"
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
		$new_input = [];
		$post_types = get_post_types( ['public' => true], 'objects' );

		foreach ( $post_types as $post_type ) {
			$pt_name = $post_type->name;
			if ( 'attachment' === $pt_name ) continue;

			// --- 'enabled' checkbox ---
			$new_input['post_types'][$pt_name]['enabled'] = ! empty( $input['post_types'][$pt_name]['enabled'] ) ? 1 : 0;

			// --- Other checkboxes ---
			$checkboxes = ['include_title', 'include_content', 'include_date', 'include_author'];
			foreach($checkboxes as $key) {
				$new_input['post_types'][$pt_name][$key] = ! empty( $input['post_types'][$pt_name][$key] ) ? 1 : 0;
			}

			// --- Taxonomies ---
			$new_input['post_types'][$pt_name]['taxonomies'] = $input['post_types'][$pt_name]['taxonomies'] ?? [];

			// --- Custom Fields (Pro) ---
			if ( isset( $input['post_types'][$pt_name]['custom_fields'] ) ) {
				$new_input['post_types'][$pt_name]['custom_fields'] = sanitize_text_field( $input['post_types'][$pt_name]['custom_fields'] );
			}
		}

		return $new_input;
	}

	/**
	 * Convert full-width numbers and other characters in the input value to half-width, and remove unnecessary characters.
	 * @param string $input Value before being saved
	 * @return string Value after sanitization
	 */
	public function sanitize_numeric_string( $input ) {
		// Convert full-width numbers, spaces, and commas to half-width.
		$sanitized_input = mb_convert_kana( $input, 'ns' );
		$sanitized_input = str_replace( '，', ',', $sanitized_input );
		$sanitized_input = preg_replace( '/[^0-9,]/', '', $sanitized_input );

		return $sanitized_input;
	}

	/**
	 * Sanitize before saving display settings options
	 * @param array $input Option array before being saved
	 * @return array Option array after sanitization
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

	public function system_prompt_field_html() {
		$default_prompt = \FEAISearch\Ajax\FEAS_AI_Chat_Handler::get_default_system_prompt();
		$prompt = get_option( 'feas_ai_system_prompt', $default_prompt );
		?>
		<textarea
			name="feas_ai_system_prompt"
			rows="100"
			class="feas-ai-prompt-editor large-text"
		><?php echo esc_textarea( $prompt ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Customize the instructions (system prompts) for the AI assistant. If left blank, the standard prompt will be used.', 'fe-ai-search' ); ?>
		</p>
		<p class="description">
			<strong><?php esc_html_e( 'Note:', 'fe-ai-search' ); ?></strong>
			<?php esc_html_e( 'Model-specific prompts can be set in the Pro version, which will override this basic setting.', 'fe-ai-search' ); ?>
		</p>
		<?php
	}

	public function batch_size_field_html() {
		$batch_size = get_option( 'feas_ai_batch_size', 10 );
		?>
		<input
			type="number"
			name="feas_ai_batch_size"
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
	 * @since 1.2.0
	 */
	public function debug_mode_field_html() {
		$is_enabled = get_option( 'feas_ai_debug_mode_enabled' );
		?>
		<fieldset>
			<label>
				<input
					type="checkbox"
					name="feas_ai_debug_mode_enabled"
					value="1"
					<?php checked( $is_enabled, 1 ); ?>
				/>
				<?php esc_html_e( 'Enable the recording of detailed system logs for debugging purposes.', 'fe-ai-search' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'When enabled, detailed operational logs will be saved to the database (in the `wp_feas_ai_system_logs` table). Only enable this when troubleshooting issues, as it can impact performance.', 'fe-ai-search' ); ?>
			</p>
		</fieldset>
		<?php
	}

}
