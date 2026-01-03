<?php
/**
 * Handles the "License" tab for the FE AI Search settings page.
 *
 * This class is responsible for adding the license activation tab,
 * rendering its content, and registering the necessary settings fields
 * with the WordPress Settings API. This is the central UI for managing
 * the Pro license from within the Free version.
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

/**
 * Manages the UI and registration for the main license settings tab.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Admin
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_License_Settings {

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * Whether the license is active.
	 *
	 * @var bool
	 */
	private $is_license_active = false;

	/**
	 * License alert icon HTML.
	 *
	 * @var string
	 */
	private $license_alert_icon = '';

	/**
	 * Constructor.
	 *
	 * Registers all necessary hooks for the license settings UI.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Retrieve all configuration data
		$this->options = get_option( 'fe_search_ai_settings', [] );

		// Use the centralized license check
		$this->is_license_active = \FESearchAI\Core\FE_Search_AI_License::is_pro_active();

		if ( ! $this->is_license_active ) {
			$this->license_alert_icon = '<a href="' . admin_url( 'admin.php' )
			. '?page=fe-search-ai#tab_license">'
			. '<span class="dashicons dashicons-warning"></span></a>';
		}

		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'fe_search_ai_settings_tabs', [ $this, 'add_tab' ] );
		add_action( 'fe_search_ai_settings_tabs_content', [ $this, 'render_content' ] );
	}

	/**
	 * Adds the "License" tab to the settings navigation bar.
	 *
	 * Also displays a warning icon if the license is not active.
	 *
	 * @since 1.0.0
	 */
	public function add_tab() {
		$icon = '';
		if ( class_exists( '\\FESearchAI\\Pro\\Admin\\FE_Search_AI_Pro_Settings' ) && ! $this->is_license_active ) {
			$icon = '<span class="dashicons dashicons-warning" style="color: #f59e0b;"></span>';
		}
		echo '<a href="#tab_license" class="nav-tab">' . esc_html__( 'License', 'fe-search-ai' ) . ' ' . wp_kses_post( $icon ) . '</a>';
	}

	/**
	 * Renders the HTML content wrapper for the "License" tab.
	 *
	 * @since 1.0.0
	 */
	public function render_content() {
		?>
		<div id="tab_license" class="tab-content">
			<table class="form-table">
				<?php
				do_settings_fields( 'fe-search-ai', 'fe_search_ai_license_section' );
				?>
			</table>
			<?php if ( ! class_exists( '\\FESearchAI\\Pro\\Admin\\FE_Search_AI_Pro_Settings' ) || ! $this->is_license_active ) : ?>
				<div class="fe-ai-search-pro-promo">
					<hr>
					<h3>
						<?php esc_html_e( 'Unlock more with FE Search AI Pro', 'fe-search-ai' ); ?>
					</h3>
					<p>
						<?php esc_html_e( 'With a Pro license, you can:', 'fe-search-ai' ); ?>
					</p>
					<ul>
						<li>
							<h4><?php esc_html_e( 'Providers', 'fe-search-ai' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Support for additional AI providers such as DeepSeek and Qwen (planned)', 'fe-search-ai' ); ?></li>
								<li>
									<?php esc_html_e( 'Configurable custom endpoints', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Connect to OSS-based providers such as local LLMs (LocalAI, Llama) and BGE-based embedding models', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'Failover feature (planned)', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Automatically route requests to another provider when the primary AI provider is unavailable', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Models', 'fe-search-ai' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Choose which model to use for each AI provider', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'For each provider, choose models that fit your needs, from the latest GPT, Gemini, and Claude Sonnet models to lightweight, cost-efficient options', 'fe-search-ai' ); ?></li>
										<li><?php esc_html_e( 'Add and select embedding models as well', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Sync', 'fe-search-ai' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Edit stop words', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Exclude noisy terms to improve search accuracy', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
								<li><?php esc_html_e( 'Add custom fields to the search index', 'fe-search-ai' ); ?></li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Prompts', 'fe-search-ai' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Configure custom prompts for each AI provider', 'fe-search-ai' ); ?></li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Display', 'fe-search-ai' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Configure detailed display rules for the chat window (by post type, page type, etc.)', 'fe-search-ai' ); ?></li>
								<li><?php esc_html_e( 'Configure a dedicated page for full-screen chat view', 'fe-search-ai' ); ?></li>
								<li>
									<?php esc_html_e( 'User consent opt-in feature', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Collect consent to Terms of Use and Privacy Policy directly in the chat', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Security', 'fe-search-ai' ); ?></h4>
							<ul>
								<li>
									<?php esc_html_e( 'Edit blocked words', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Mask specific words and add extra protection against prompt injection', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'API usage limits (rate limiting)', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Limit the number of requests per IP address per hour', 'fe-search-ai' ); ?></li>
										<li><?php esc_html_e( 'Limit the total number of requests per site per day', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'Notification settings', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Configure thresholds for sending notifications to administrators', 'fe-search-ai' ); ?></li>
										<li><?php esc_html_e( 'Configure notification email addresses', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Advanced settings', 'fe-search-ai' ); ?></h4>
							<ul>
								<li>
									<?php esc_html_e( 'MCP server feature', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Provide a set of tools for AI agents to use', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'API token management', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Enable use from external clients such as headless CMS or mobile apps', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'Detailed logging', 'fe-search-ai' ); ?>
									<ul>
										<li><?php esc_html_e( 'Log not only API communication but also AI responses and user feedback', 'fe-search-ai' ); ?></li>
										<li><?php esc_html_e( 'Provide a log viewer with list view, filtering, and CSV export', 'fe-search-ai' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( '“FE Search Advanced” included (planned)', 'fe-search-ai' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'A full license for the high‑performance faceted search plugin used by over 1,300 companies is included at no additional cost.', 'fe-search-ai' ); ?></li>
								<li><?php esc_html_e( 'Build advanced faceted search forms with an intuitive UI', 'fe-search-ai' ); ?></li>
								<li><?php esc_html_e( 'Real-time faceted search with instant results without page reloads', 'fe-search-ai' ); ?></li>
								<li><?php esc_html_e( 'Can be used on its own as a traditional, non-AI faceted search', 'fe-search-ai' ); ?></li>
								<li>
									<?php
									printf(
										/* translators: %s: "AI hybrid search" */
										__( 'Achieve %s that combines traditional faceted search with AI-powered natural language search (planned)', 'fe-search-ai' ),
										'<strong>' . esc_html__( 'AI hybrid search', 'fe-search-ai' ) . '</strong>'
									);
									?>
								</li>
							</ul>
						</li>
					</ul>
					<p>
						<a
							href="<?php echo esc_url( FE_AI_SEARCH_PRO_URL ); ?>"
							target="_blank"
							class="button button-primary"
						>
							<?php esc_html_e( 'View FE Search AI Pro', 'fe-search-ai' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Registers the settings section and fields for the license tab.
	 *
	 * @since 1.0.0
	 */
	public function register_settings() {
		add_settings_section(
			'fe_search_ai_license_section',
			__( 'License Activation', 'fe-search-ai' ),
			null,
			'fe-search-ai'
		);

		register_setting(
			'fe-ai-settings',
			'fe_search_ai_license_key'
		);

		add_settings_field(
			'fe_search_ai_license_key',
			__( 'License Key', 'fe-search-ai' ),
			[ $this, 'field_html' ],
			'fe-search-ai',
			'fe_search_ai_license_section'
		);
	}

	/**
	 * Renders the HTML for the license key input field and action buttons.
	 *
	 * @since 1.0.0
	 */
	public function field_html() {
		$license_data = get_option( 'fe_search_ai_license', [] );
		$products = $license_data['products'] ?? [];

		// Get and decrypt the Pro license key (product ID 65)
		$license_key = '';
		if ( isset( $products[65]['key'] ) ) {
			$encrypted_key = $products[65]['key'];
			$license_key = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
		}

		$license_status = \FESearchAI\Core\FE_Search_AI_License::is_pro_active() ? 'active' : 'inactive';
		?>
		<input
			type="password"
			autocomplete="off"
			id="fe_search_ai_license_key_input"
			name="fe_search_ai_license_key_input"
			value="<?php echo esc_attr( $license_key ); ?>"
			class="regular-text"
			style="max-width: 300px;"
		>
		<button
			type="button"
			id="fe_search_ai_license_toggle_visibility"
			class="button button-secondary"
			style="margin-left: 8px;"
		>
			<?php esc_html_e( 'Show', 'fe-search-ai' ); ?>
		</button>

		<?php if ( 'active' === $license_status ) : ?>

			<button type="button" id="fe_search_ai_license_deactivate" class="button button-secondary"><?php esc_html_e( 'Deactivate', 'fe-search-ai' ); ?></button>
			<p class="description" style="color: green; font-weight: bold;">
				<?php esc_html_e( 'The license is valid.', 'fe-search-ai' ); ?>
			</p>
			<?php
				// Get license data from the correct structure
				$products = $license_data['products'] ?? [];
				$pro_data = $products[65]['data'] ?? [];
				
				$expires_at          = $pro_data['expiresAt'] ?? '';
				$times_activated     = $pro_data['timesActivated'] ?? null;
				$times_activated_max = $pro_data['timesActivatedMax'] ?? null;
				$remaining_days      = '';

			if ( ! empty( $expires_at ) ) {
				try {
					$expires_ts     = strtotime( $expires_at );
					$today_midnight = strtotime( 'today midnight' );
					if ( $expires_ts ) {
						$diff_days      = (int) floor( ( $expires_ts - $today_midnight ) / DAY_IN_SECONDS );
						$remaining_days = $diff_days;
					}
				} catch ( \Throwable $e ) {
					$remaining_days = '';
				}
			}

				$expires_text = '';
			if ( ! empty( $expires_at ) ) {
				// Format as Y-m-d for display.
				$expires_text = sprintf(
					/* translators: 1: expiration date, 2: remaining days */
					__( 'License expiration date: %1$s (remaining %2$s days)', 'fe-search-ai' ),
					esc_html( date_i18n( 'Y-m-d', strtotime( $expires_at ) ) ),
					$remaining_days !== '' ? esc_html( (string) $remaining_days ) : '600'
				);
			}

				$max_text = is_null( $times_activated_max ) ? __( 'Unlimited', 'fe-search-ai' ) : (string) (int) $times_activated_max;
			?>
			<?php if ( ! empty( $expires_text ) ) : ?>
				<p class="description">
					<?php echo esc_html( $expires_text ); ?>
				</p>
			<?php endif; ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: times activated, 2: max activations */
					esc_html__( 'Activation count: %1$s / %2$s', 'fe-search-ai' ),
					esc_html( (string) ( $times_activated ?? 0 ) ),
					esc_html( $max_text )
				);
				?>
			</p>

		<?php else : ?>

			<button type="button" id="fe_search_ai_license_activate" class="button button-primary"><?php esc_html_e( 'Activate', 'fe-search-ai' ); ?></button>
			<p class="description">
				<?php esc_html_e( 'Enter the license key you received at the time of purchase and press the "Activate" button.', 'fe-search-ai' ); ?>
			</p>

			<?php
			$error = get_transient( 'fe_search_ai_license_error' );
			if ( $error ) :
				?>
				<p style="color: red;"><?php echo esc_html( $error ); ?></p>
				<?php
				delete_transient( 'fe_search_ai_license_error' );
			endif;
			?>

		<?php endif; ?>

		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<?php
	}
}
