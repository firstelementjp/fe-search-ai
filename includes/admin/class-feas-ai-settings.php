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

	public function __construct() {
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
				<a href="#tab-api" class="nav-tab">API設定</a>
				<a href="#tab-sync" class="nav-tab">同期設定</a>
				<a href="#tab-display" class="nav-tab">表示設定</a>
				<a href="#tab-data" class="nav-tab">データ管理</a>
				<?php do_action( 'feas_ai_settings_tabs' ); ?>
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

				<div id="tab-data" class="tab-content">
					<?php do_settings_sections( 'feas_ai_data_section' ); ?>
					<table class="form-table">
						<?php do_settings_fields( 'fe-ai-search', 'feas_ai_data_section' ); ?>
					</table>
					<?php do_action( 'feas_ai_after_data_settings_fields' ); ?>
				</div>

				<?php do_action( 'feas_ai_settings_tabs_content' ); ?>

				<?php submit_button( '設定を保存' ); ?>
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

		// Synchronization Settings Section
		add_settings_section(
			'feas_ai_sync_section',
			__( 'Sync Settings', 'fe-ai-search' ),
			null,
			$page_slug
		);
		register_setting( $settings_group, 'feas_ai_sync_options' );
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
			'feas_ai_sync_ui',
			__( 'Manual Sync', 'fe-ai-search' ),
			[ $this, 'sync_ui_field_html' ],
			$page_slug,
			'feas_ai_sync_section'
		);

		// Display Settings Section
		add_settings_section(
			'feas_ai_display_section',
			__( 'Display settings', 'fe-ai-search' ),
			null,
			$page_slug
		);
		add_settings_field(
			'feas_ai_display_options',
			__( 'Chat display settings', 'fe-ai-search' ),
			[ $this, 'display_options_field_html' ],
			$page_slug,
			'feas_ai_display_section'
		);
		register_setting(
			$settings_group,
			'feas_ai_display_options',
			[ 'sanitize_callback' => [ $this, 'sanitize_display_options' ] ]
		);

		// Data Management Section
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
	}

	/**
	 * Determine whether the Pro version add-on is enabled
	 * @return bool
	 */
	private function is_pro_active() {
		return class_exists('FEAS_AI_Pro_Analytics');
	}

	public function chat_provider_field_html() {
		$provider = get_option( 'feas_ai_chat_provider', 'openai' );

		$providers = [
			'openai'    => __( 'OpenAI (GPT-4o)', 'fe-ai-search' ),
			'google'    => __( 'Google (Gemini)', 'fe-ai-search' ),
			'anthropic' => __( 'Anthropic (Claude)', 'fe-ai-search' ),
		];

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
			'openai'    => __( 'OpenAI (text-embedding-3)', 'fe-ai-search' ),
			'google'    => __( 'Google (text-embedding-004)', 'fe-ai-search' ),
			'anthropic' => __( 'Anthropic (Claude) - (Not available)', 'fe-ai-search' ),
		];

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
			<?php esc_html_e( 'Select an AI that will vectorize (convert) your site content into a format that AI can understand.', 'fe-ai-search' ); ?>
		</p>
		<?php
	}

	public function openai_api_key_field_html() {
		$api_key = get_option( 'feas_ai_openai_api_key' );
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
		<?php
	}

	public function google_api_key_field_html() {
		$api_key = get_option( 'feas_ai_google_api_key' );
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
		<?php
	}

	public function anthropic_api_key_field_html() {
		$api_key = get_option( 'feas_ai_anthropic_api_key' );
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
		<?php
	}

	public function sync_options_field_html() {
		$options = get_option( 'feas_ai_sync_options', [] );

		echo wp_kses_post(
			__(
				'<p class="description">' .
				'Select the post types you want to include in your AI search ' .
				'and the metadata you want to include in chunks for each post type.' .
				'</p>',
				'fe-ai-search'
			)
		);
		echo '<div id="feas-ai-sync-options-accordion">';

		// Synchronization Target
		$post_types = get_post_types( ['public' => true], 'objects' );
		$excluded_post_types = ['attachment'];

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $excluded_post_types ) ) {
				continue;
			}

			$defaults = [
				'enabled'         => ($post_type->name === 'post' || $post_type->name === 'page'),
				'include_title'   => true,
				'include_content' => true,
				'include_date'    => true,
				'include_author'  => true,
			];

			$pt_options = wp_parse_args( $options['post_types'][ $post_type->name ] ?? [], $defaults );
//
			// if ( ! isset( $options['post_types'][ $post_type->name ] ) ) {
			// 	$is_enabled = ($post_type->name === 'post' || $post_type->name === 'page');
			// 	$include_title = true;
			// 	$include_content = true;
			// 	$include_date = true;
			// } else {
			// 	$is_enabled = $pt_options['enabled'] ?? false;
			// 	$include_title = $pt_options['include_title'] ?? false;
			// 	$include_content = $pt_options['include_content'] ?? false;
			// 	$include_date = $pt_options['include_date'] ?? false;
			// }

			?>
			<div class="post-type-accordion-item" style="border: 1px solid #c3c4c7; margin-bottom: -1px; background: #fff;">
				<h4 class="accordion-title" style="margin: 0; padding: 10px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #ddd;">
					<label>
						<input
							type="checkbox"
							name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][enabled]"
							value="1"
							<?php checked( $pt_options['enabled'] ); ?>
						>
						<?php echo esc_html( $post_type->label ); ?> (<code><?php echo esc_html( $post_type->name ); ?></code>)
					</label>
				</h4>
				<div class="accordion-content" style="<?php echo $pt_options['enabled'] ? '' : 'display: none;'; ?> padding: 15px; border-top: 1px solid #ddd;">
					<fieldset>
						<label>
							<input
								type="checkbox"
								name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][include_title]"
								value="1"
								<?php checked( $pt_options['include_title'] ); ?>
							>
							<?php esc_html_e( 'Include Post Title', 'fe-ai-search' ); ?>
						</label><br>
						<label>
							<input
								type="checkbox"
								name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][include_content]"
								value="1"
								<?php checked( $pt_options['include_content'] ); ?>
							>
							<?php esc_html_e( 'Include Post Content', 'fe-ai-search' ); ?>
						</label><br>
						<label>
							<input
								type="checkbox"
								name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][include_date]"
								value="1"
								<?php checked( $pt_options['include_date'] ); ?>
							>
							<?php esc_html_e( 'Include Post Date', 'fe-ai-search' ); ?>
						</label><br>
						<label>
							<input
								type="checkbox"
								name="feas_ai_sync_options[post_types][<?php echo esc_attr($post_type->name); ?>][include_author]"
								value="1"
								<?php checked($pt_options['include_author']); ?>
							>
							<?php esc_html_e( 'Include Post Author', 'fe-ai-search' ); ?>
						</label><br>

						<?php
						$taxonomies = get_object_taxonomies( $post_type->name, 'objects' );
						if ( ! empty( $taxonomies ) ) {
							foreach ( $taxonomies as $tax ) {
								if ( ! $tax->public ) {
									continue;
								}
								$is_checked = ! empty( $pt_options['taxonomies'] ) && in_array( $tax->name, $pt_options['taxonomies'] );
								?>
								<label>
									<input
										type="checkbox"
										name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][taxonomies][]"
										value="<?php echo esc_attr( $tax->name ); ?>"
										<?php checked( $is_checked ); ?>
									>
									<?php
									printf(
										// translators: %s: Taxonomy label (e.g., "Categories", "Tags")
										esc_html__( 'Including %s', 'fe-ai-search' ),
										esc_html( $tax->label )
									);
									?>
								</label><br>
								<?php
							}
						}
						?>

						<div style="margin-top: 10px;">
							<label for="feas_ai_custom_fields_<?php echo esc_attr( $post_type->name ); ?>">
								<strong><?php esc_html_e( 'Include Custom Fields (Pro Feature)', 'fe-ai-search' ); ?></strong>
							</label><br>
							<input
								type="text"
								id="feas_ai_custom_fields_<?php echo esc_attr( $post_type->name ); ?>"
								name="feas_ai_sync_options[post_types][<?php echo esc_attr( $post_type->name ); ?>][custom_fields]"
								value="<?php echo esc_attr( $pt_options['custom_fields'] ?? '' ); ?>"
								class="regular-text"
								placeholder="field_name_1, field_name_2"
								<?php disabled( ! $this->is_pro_active() ); ?>
							>
							<p class="description">
								<?php esc_html_e( 'Enter the keys of the custom fields you want to include, separated by commas.', 'fe-ai-search' ); ?>
							</p>
							<?php if ( ! $this->is_pro_active() ) : ?>
								<p class="description">
									<?php esc_html_e( 'This feature is available in the Pro version.', 'fe-ai-search' ); ?>
									<a href="#" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'fe-ai-search' ); ?></a>
								</p>
							<?php endif; ?>
						</div>
					</fieldset>
				</div>
			</div>
			<?php
		}
		echo '</div>';
		?>
		<script>
		jQuery(document).ready(function($) {
			$('#feas-ai-sync-options-accordion .accordion-title').on('click', function(e){
				if (e.target.tagName === 'INPUT') return;
				$(this).next('.accordion-content').slideToggle();
			});
		});
		</script>
		<?php
	}

	public function enable_logging_field_html() {
		$option = get_option( 'feas_ai_enable_logging' );
		?>
		<fieldset>
			<label>
				<input type="checkbox" name="feas_ai_enable_logging" value="1"
					<?php checked( $option, '1' ); ?>
					<?php disabled( ! $this->is_pro_active() ); // Proでなければdisabled ?>
				/>
				<?php _e( 'Save conversation history with users', 'fe-ai-search' ); ?>
			</label>
			<?php if ( ! $this->is_pro_active() ) : // Display guidance unless Pro ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: URL to the Pro version upgrade page. */
						wp_kses_post( __( 'This feature is available in the Pro version. <a href="%s" target="_blank">Upgrade to Pro</a>', 'fe-ai-search' ) ),
						'https://example.com/pro-upgrade'
					);
					?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Once you enable this feature, you can analyze conversations on the Analytics page of your admin panel.', 'fe-ai-search' ); ?>
				</p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	public function log_retention_field_html() {
		$days = get_option( 'feas_ai_log_retention_days', 30 );
		?>
		<input
			type="number"
			name="feas_ai_log_retention_days"
			value="<?php echo esc_attr( $days ); ?>"
			class="small-text"
			<?php disabled( ! $this->is_pro_active() ); ?>
		>
		<?php esc_html_e( 'days', 'fe-ai-search' ); ?>
		<p class="description">
			<?php esc_html_e( 'Search logs older than this will be automatically deleted every day. Set this to 0 to never delete them.', 'fe-ai-search' ); ?>
		</p>
		<?php if ( ! $this->is_pro_active() ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to the Pro version upgrade page. */
					wp_kses_post( __( 'This feature is available in the Pro version. <a href="%s" target="_blank">Upgrade to Pro</a>', 'fe-ai-search' ) ),
					'https://example.com/pro-upgrade'
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	public function custom_prompt_field_html() {
		$default_prompt = FEAS_AI_Ajax::get_default_system_prompt();
		$custom_prompt  = get_option( 'feas_ai_custom_system_prompt', $default_prompt );
		?>
		<textarea
			name="feas_ai_custom_system_prompt"
			rows="20"
			class="large-text feas-ai-prompt-editor"
		><?php echo esc_textarea( $custom_prompt ); ?></textarea>
		<p class="description">
			<?php esc_html_e( "Customize the instructions (system prompts) for the AI assistant. If left blank, the plugin's standard prompts will be used.", 'fe-ai-search' ); ?>
		</p>
		<?php
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
				<button id="feas-ai-start-sync" class="button button-secondary">
					<?php esc_html_e( 'Start syncing', 'fe-ai-search' ); ?>
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
			<?php _e( 'This will delete all synced vector data and keyword indexes. AI search will no longer work until you sync again.', 'fe-ai-search' ); ?>
		</p>
		<button type="button" id="feas-ai-delete-vectors-button" class="button button-secondary">
			<?php _e( 'Delete all synced data', 'fe-ai-search' ); ?>
		</button>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<p id="feas-ai-delete-status" style="display: inline-block; margin-left: 10px;"></p>
		<?php
	}

	public function display_options_field_html() {
		$options = get_option( 'feas_ai_display_options', [] );

		// デフォルト値を設定
		$defaults = [
			'display_mode'     => 'float',
			'window_title'     => __( 'AI search within the site', 'fe-ai-search' ),
			'greeting_message' => __( 'Hello! Please feel free to ask any questions about the information on this site.', 'fe-ai-search' ),
			'placeholder_text' => __( 'Enter your question...', 'fe-ai-search' ),
			'submit_button_text' => __( 'Send', 'fe-ai-search' ),
			'display_rules'    => [
				'show_on_front_page' => '1',
				'show_on_archives'   => '1',
				'post_types'         => [],
				'include_ids'        => '',
				'exclude_ids'        => '',
			],
			'disable_css'      => false,
		];
		$options = wp_parse_args($options, $defaults);

		?>

		<h4><?php esc_html_e( 'Display Mode', 'fe-ai-search' ); ?></h4>
		<fieldset>
			<label>
				<input
					type="radio"
					name="feas_ai_display_options[display_mode]"
					value="float"
					<?php checked( 'float', $options['display_mode'] ); ?>
				>
				<span><?php esc_html_e( 'Floating Mode', 'fe-ai-search' ); ?></span>
			</label><br>
			<label>
				<input
					type="radio"
					name="feas_ai_display_options[display_mode]"
					value="shortcode"
					<?php checked( 'shortcode', $options['display_mode'] ); ?>
				>
				<span><?php esc_html_e( 'Manual Installation Mode', 'fe-ai-search' ); ?> (<code>[feas_ai_chat]</code>)</span>
			</label>
		</fieldset>

		<hr>

		<h4><?php esc_html_e( 'Floating Display Rules', 'fe-ai-search' ); ?></h4>
		<p class="description">
			<?php esc_html_e( 'When floating mode is enabled, limit the pages on which the chat UI is displayed.', 'fe-ai-search' ); ?>
		</p>
		<fieldset>
			<p>
				<label>
					<input
						type="checkbox"
						name="feas_ai_display_options[display_rules][show_on_front_page]"
						value="1"
						<?php checked( $options['display_rules']['show_on_front_page'] ?? '1' ); ?>
					>
					<?php esc_html_e( 'Display on the top page', 'fe-ai-search' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input
						type="checkbox"
						name="feas_ai_display_options[display_rules][show_on_archives]"
						value="1"
						<?php checked( $options['display_rules']['show_on_archives'] ?? '1' ); ?>
					>
					<?php esc_html_e( 'Display on archive page (list page)', 'fe-ai-search' ); ?>
				</label>
			</p>

			<h5 style="margin-bottom: 0.5em;"><?php esc_html_e( 'Post Type Rules', 'fe-ai-search' ); ?></h5>
			<div style="padding-left: 25px;">
				<?php
				$all_post_types  = get_post_types( [ 'public' => true ], 'objects' );
				$rule_post_types = $options['display_rules']['post_types'] ?? [];
				foreach ( $all_post_types as $pt ) {
					if ( 'attachment' === $pt->name ) {
						continue;
					}
					$is_checked = ! empty( $rule_post_types[ $pt->name ] );
					?>
					<label>
						<input
							type="checkbox"
							name="feas_ai_display_options[display_rules][post_types][<?php echo esc_attr( $pt->name ); ?>]"
							value="1"
							<?php checked( $is_checked ); ?>
						>
						<?php
						printf(
							/* translators: %s: Post type label (e.g., "Posts", "Pages") */
							esc_html__( 'Display on individual pages of %s', 'fe-ai-search' ),
							esc_html( $pt->label )
						);
						?>
					</label><br>
					<?php
				}
				?>
			</div>

			<h5 style="margin-top: 1.5em; margin-bottom: 0.5em;"><?php esc_html_e( 'Individual rules by post ID', 'fe-ai-search' ); ?></h5>
			<p>
				<label for="feas_ai_include_ids">
					<?php esc_html_e( 'Show only with these post IDs:', 'fe-ai-search' ); ?>
				</label><br>
				<input
					type="text"
					id="feas_ai_include_ids"
					name="feas_ai_display_options[display_rules][include_ids]"
					value="<?php echo esc_attr( $options['display_rules']['include_ids'] ?? '' ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. 10, 25, 103', 'fe-ai-search' ); ?>"
				>
				<p class="description">
					<?php esc_html_e( 'If you enter an ID here, other rules will be ignored.', 'fe-ai-search' ); ?>
				</p>
			</p>
			<p>
				<label for="feas_ai_exclude_ids">
					<?php esc_html_e( "Don't show these post IDs:", 'fe-ai-search' ); ?>
				</label><br>
				<input
					type="text"
					id="feas_ai_exclude_ids"
					name="feas_ai_display_options[display_rules][exclude_ids]"
					value="<?php echo esc_attr( $options['display_rules']['exclude_ids'] ?? '' ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. 15, 30', 'fe-ai-search' ); ?>"
				>
			</p>
		</fieldset>

		<hr>
		<h4><?php esc_html_e( 'Chat message settings', 'fe-ai-search' ); ?></h4>
		<p>
			<label for="feas_window_title"><?php esc_html_e( 'Window title', 'fe-ai-search' ); ?></label><br>
			<input
				type="text"
				id="feas_window_title"
				name="feas_ai_display_options[window_title]"
				value="<?php echo esc_attr( $options['window_title'] ); ?>"
				class="regular-text"
			>
		</p>
		<p>
			<label for="feas_greeting_message"><?php esc_html_e( 'First greeting', 'fe-ai-search' ); ?></label><br>
			<textarea
				id="feas_greeting_message"
				name="feas_ai_display_options[greeting_message]"
				rows="3"
				class="large-text"
			><?php echo esc_textarea( $options['greeting_message'] ); ?></textarea>
		</p>
		<p>
			<label for="feas_placeholder_text"><?php esc_html_e( 'Input field placeholders', 'fe-ai-search' ); ?></label><br>
			<input
				type="text"
				id="feas_placeholder_text"
				name="feas_ai_display_options[placeholder_text]"
				value="<?php echo esc_attr( $options['placeholder_text'] ); ?>"
				class="regular-text"
			>
		</p>
		<p>
			<label for="feas_submit_button_text"><?php esc_html_e( 'Submit button text', 'fe-ai-search' ); ?></label><br>
			<input
				type="text"
				id="feas_submit_button_text"
				name="feas_ai_display_options[submit_button_text]"
				value="<?php echo esc_attr( $options['submit_button_text'] ); ?>"
				class="regular-text"
			>
		</p>

		<hr>
		<h4><?php esc_html_e( 'Advanced Settings', 'fe-ai-search' ); ?></h4>
		<fieldset>
			<label>
				<input
					type="checkbox"
					name="feas_ai_display_options[disable_css]"
					value="1"
					<?php checked( $options['disable_css'], '1' ); ?>
				/>
				<?php esc_html_e( 'Do not load CSS included in plugins', 'fe-ai-search' ); ?>
			</label>
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
			<?php
			echo wp_kses_post(
				__(
					'If you want to sync only certain posts, enter the post IDs separated by commas (e.g. 10, 25, 103).<br>' .
					'<strong>Note:</strong> If you enter any here, the "Post types to sync" setting will be ignored ' .
					'and only the posts with those IDs will be synced.',
					'fe-ai-search'
				)
			);
			?>
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
			esc_html_e(
				'If you want to exclude posts from synchronization, enter the post IDs separated by commas (e.g., 15, 30).',
				'fe-ai-search'
			);
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
			echo wp_kses_post(
				__(
					'Limits the number of posts to be synchronized to the specified number, starting from the most recent. ' .
					'Set this if you are trying to synchronize on a trial basis or want to reduce API costs.<br>' .
					'<strong>0</strong> will synchronize all eligible posts.',
					'fe-ai-search'
				)
			);
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
				echo wp_kses_post(
					__(
						'Check this box if you want to stop using this plugin completely, rather than just temporarily disabling it.<br>' .
						'If you leave this box unchecked, your previous settings and sync data will be restored when you reinstall the plugin.',
						'fe-ai-search'
					)
				);
				?>
			</p>
		</fieldset>
		<?php
	}

	public function sync_post_types_field_html() {
		// サイトに登録されている、検索に適した投稿タイプを取得
		$post_types = get_post_types( array('public' => true), 'objects' );

		// 不要な投稿タイプを除外
		$excluded_post_types = array('attachment');

		// DBに保存されている設定値を取得（デフォルトは投稿と固定ページ）
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
		echo wp_kses_post( __( '<p class="description">Select the post types you want to include in AI search.</p>', 'fe-ai-search' ) );
	}

	/**
	 * 入力値に含まれる全角数字などを半角に変換し、不要な文字を削除する
	 * @param string $input 保存される前の値
	 * @return string サニタイズ後の値
	 */
	public function sanitize_numeric_string( $input ) {
		// 全角の数字・スペース・カンマを半角に変換
		$sanitized_input = mb_convert_kana( $input, 'ns' );
		$sanitized_input = str_replace( '，', ',', $sanitized_input );
		$sanitized_input = preg_replace( '/[^0-9,]/', '', $sanitized_input );

		return $sanitized_input;
	}

	/**
	 * 表示設定オプションを保存する前にサニタイズする
	 * @param array $input 保存される前のオプション配列
	 * @return array サニタイズ後のオプション配列
	 */
	public function sanitize_display_options( $input ) {
		$new_input = $input;

		// --- Include/Exclude IDの全角数字を半角に変換 ---
		if ( isset( $new_input['display_rules']['include_ids'] ) ) {
			// 'n' オプションは数字を半角に変換
			$new_input['display_rules']['include_ids'] = mb_convert_kana( $new_input['display_rules']['include_ids'], 'n' );
		}
		if ( isset( $new_input['display_rules']['exclude_ids'] ) ) {
			$new_input['display_rules']['exclude_ids'] = mb_convert_kana( $new_input['display_rules']['exclude_ids'], 'n' );
		}

		return $new_input;
	}

}
