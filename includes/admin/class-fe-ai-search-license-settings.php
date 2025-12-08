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

namespace FEAISearch\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FEAISearch\Core\FE_AI_Search_License;

/**
 * Manages the UI and registration for the main license settings tab.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Admin
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_License_Settings {

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
		$this->options = get_option( 'fe_ai_search_settings', [] );

		/**
		 * Check the status of the license (stored in its own option).
		 */
		// Treat the license as active when the status is "active" and the product ID
		// matches the Pro add-on (productId = 65 in License Manager for WooCommerce).
		$this->is_license_active = FE_AI_Search_License::is_pro_active();

		if ( ! $this->is_license_active ) {
			$this->license_alert_icon = '<a href="' . admin_url( 'admin.php' )
			. '?page=fe-ai-search#tab-license">'
			. '<span class="dashicons dashicons-warning"></span></a>';
		}

		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'fe_ai_search_settings_tabs', [ $this, 'add_tab' ] );
		add_action( 'fe_ai_search_settings_tabs_content', [ $this, 'render_content' ] );
	}

	/**
	 * Adds the "License" tab to the settings navigation bar.
	 *
	 * Also displays a warning icon if the license is not active.
	 *
	 * @since 1.0.0
	 */
	public function add_tab() {
		$icon          = '';
		$has_pro_addon = class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' );
		if ( ( $has_pro_addon && ! $this->is_license_active ) || ( ! $has_pro_addon && $this->is_license_active ) ) {
			// Show a yellow warning icon when the Pro add-on is installed but the license is inactive,
			// or when the license is active but the Pro add-on is not installed/active.
			$icon = '<span class="dashicons dashicons-warning" style="color: #f59e0b;"></span>';
		}
		echo '<a href="#tab_license" class="nav-tab">' . esc_html__( 'License', 'fe-ai-search' ) . ' ' . wp_kses_post( $icon ) . '</a>';
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
				do_settings_fields( 'fe-ai-search', 'fe_ai_search_license_section' );
				?>
			</table>
			<?php if ( ! class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' ) || ! $this->is_license_active ) : ?>
				<div class="fe-ai-search-pro-promo">
					<hr>
					<h3>
						<?php esc_html_e( 'Unlock more with FE Search AI Pro', 'fe-ai-search' ); ?>
					</h3>
					<p>
						<?php esc_html_e( 'With a Pro license, you can:', 'fe-ai-search' ); ?>
					</p>
					<ul>
						<li>
							<h4><?php esc_html_e( 'Providers', 'fe-ai-search' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Support for additional AI providers such as DeepSeek and Qwen (planned)', 'fe-ai-search' ); ?></li>
								<li>
									<?php esc_html_e( 'Configurable custom endpoints', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Connect to OSS-based providers such as local LLMs (LocalAI, Llama) and BGE-based embedding models', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'Failover feature (planned)', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Automatically route requests to another provider when the primary AI provider is unavailable', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Models', 'fe-ai-search' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Choose which model to use for each AI provider', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'For each provider, choose models that fit your needs, from the latest GPT, Gemini, and Claude Sonnet models to lightweight, cost-efficient options', 'fe-ai-search' ); ?></li>
										<li><?php esc_html_e( 'Add and select embedding models as well', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Sync', 'fe-ai-search' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Edit stop words', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Exclude noisy terms to improve search accuracy', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
								<li><?php esc_html_e( 'Add custom fields to the search index', 'fe-ai-search' ); ?></li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Prompts', 'fe-ai-search' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Configure custom prompts for each AI provider', 'fe-ai-search' ); ?></li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Display', 'fe-ai-search' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Configure detailed display rules for the chat window (by post type, page type, etc.)', 'fe-ai-search' ); ?></li>
								<li><?php esc_html_e( 'Configure a dedicated page for full-screen chat view', 'fe-ai-search' ); ?></li>
								<li>
									<?php esc_html_e( 'User consent opt-in feature', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Collect consent to Terms of Use and Privacy Policy directly in the chat', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Security', 'fe-ai-search' ); ?></h4>
							<ul>
								<li>
									<?php esc_html_e( 'Edit blocked words', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Mask specific words and add extra protection against prompt injection', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'API usage limits (rate limiting)', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Limit the number of requests per IP address per hour', 'fe-ai-search' ); ?></li>
										<li><?php esc_html_e( 'Limit the total number of requests per site per day', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'Notification settings', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Configure thresholds for sending notifications to administrators', 'fe-ai-search' ); ?></li>
										<li><?php esc_html_e( 'Configure notification email addresses', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( 'Advanced settings', 'fe-ai-search' ); ?></h4>
							<ul>
								<li>
									<?php esc_html_e( 'MCP server feature', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Provide a set of tools for AI agents to use', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'API token management', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Enable use from external clients such as headless CMS or mobile apps', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
								<li>
									<?php esc_html_e( 'Detailed logging', 'fe-ai-search' ); ?>
									<ul>
										<li><?php esc_html_e( 'Log not only API communication but also AI responses and user feedback', 'fe-ai-search' ); ?></li>
										<li><?php esc_html_e( 'Provide a log viewer with list view, filtering, and CSV export', 'fe-ai-search' ); ?></li>
									</ul>
								</li>
							</ul>
						</li>

						<li>
							<h4><?php esc_html_e( '“FE Search Advanced” included (planned)', 'fe-ai-search' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'A full license for the high‑performance faceted search plugin used by over 1,300 companies is included at no additional cost.', 'fe-ai-search' ); ?></li>
								<li><?php esc_html_e( 'Build advanced faceted search forms with an intuitive UI', 'fe-ai-search' ); ?></li>
								<li><?php esc_html_e( 'Real-time faceted search with instant results without page reloads', 'fe-ai-search' ); ?></li>
								<li><?php esc_html_e( 'Can be used on its own as a traditional, non-AI faceted search', 'fe-ai-search' ); ?></li>
								<li>
									<?php
									printf(
										/* translators: %s: "AI hybrid search" */
										__( 'Achieve %s that combines traditional faceted search with AI-powered natural language search (planned)', 'fe-ai-search' ),
										'<strong>' . esc_html__( 'AI hybrid search', 'fe-ai-search' ) . '</strong>'
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
							<?php esc_html_e( 'View FE Search AI Pro', 'fe-ai-search' ); ?>
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
			'fe_ai_search_license_section',
			__( 'License Activation', 'fe-ai-search' ),
			null,
			'fe-ai-search'
		);

		register_setting(
			'fe-ai-settings',
			'fe_ai_search_license_key'
		);

		add_settings_field(
			'fe_ai_search_license_key',
			__( 'License Key', 'fe-ai-search' ),
			[ $this, 'field_html' ],
			'fe-ai-search',
			'fe_ai_search_license_section'
		);
	}

	/**
	 * Renders the HTML for the license key input field and action buttons.
	 *
	 * @since 1.0.0
	 */
	public function field_html() {
		$products   = FE_AI_Search_License::get_products();
		$entry_pro  = $products[ FE_AI_Search_License::PRODUCT_ID_PRO ] ?? null;
		$entry_pro  = is_array( $entry_pro ) ? $entry_pro : [];
		$entry_pine = $products[ FE_AI_Search_License::PRODUCT_ID_PINECONE ] ?? null;
		$entry_pine = is_array( $entry_pine ) ? $entry_pine : [];

		$license_key_pro    = isset( $entry_pro['key'] ) ? (string) $entry_pro['key'] : '';
		$license_status_pro = isset( $entry_pro['status'] ) ? (string) $entry_pro['status'] : 'inactive';

		$license_key_pine    = isset( $entry_pine['key'] ) ? (string) $entry_pine['key'] : '';
		$license_status_pine = isset( $entry_pine['status'] ) ? (string) $entry_pine['status'] : 'inactive';
		?>
		<p>
			<strong><?php esc_html_e( 'FE Search AI Pro License', 'fe-ai-search' ); ?></strong>
		</p>
		<input
			type="password"
			autocomplete="off"
			id="fe_ai_search_license_key_input"
			name="fe_ai_search_license_key_input"
			value="<?php echo esc_attr( $license_key_pro ); ?>"
			class="regular-text"
			style="width: 300px;"
		>
		<button
			type="button"
			id="fe_ai_search_license_toggle_visibility"
			class="button button-secondary fe-ai-search-license-toggle"
			data-target-input-id="fe_ai_search_license_key_input"
			style="margin-left: 8px;"
		>
			<?php esc_html_e( 'Show', 'fe-ai-search' ); ?>
		</button>

		<?php if ( 'active' === $license_status_pro ) : ?>

			<button
				type="button"
				id="fe_ai_search_license_deactivate"
				class="button button-secondary"
				data-license-product-id="<?php echo esc_attr( (string) FE_AI_Search_License::PRODUCT_ID_PRO ); ?>"
				data-license-input-id="fe_ai_search_license_key_input"
				data-license-action="deactivate"
			>
				<?php esc_html_e( 'Deactivate', 'fe-ai-search' ); ?>
			</button>
			<p class="description" style="color: green; font-weight: bold;">
				<?php esc_html_e( 'The license is valid.', 'fe-ai-search' ); ?>
			</p>
			<?php if ( ! class_exists( '\\FEAISearch\\Pro\\Admin\\FE_AI_Search_Pro_Settings' ) ) : ?>
				<p class="description" style="color: #dc2626;">
					<?php esc_html_e( 'The Pro add-on plugin is not installed or not active. Please install and activate "FE Search AI Pro" to use Pro features.', 'fe-ai-search' ); ?>
				</p>
			<?php endif; ?>
			<?php
				$data                = isset( $entry_pro['data'] ) && is_array( $entry_pro['data'] ) ? $entry_pro['data'] : [];
				$expires_at          = $data['expiresAt'] ?? '';
				$times_activated     = $data['timesActivated'] ?? null;
				$times_activated_max = $data['timesActivatedMax'] ?? null;
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
					__( 'License expiration date: %1$s (remaining %2$s days)', 'fe-ai-search' ),
					esc_html( date_i18n( 'Y-m-d', strtotime( $expires_at ) ) ),
					$remaining_days !== '' ? esc_html( (string) $remaining_days ) : '600'
				);
			}

				$max_text = is_null( $times_activated_max ) ? __( 'Unlimited', 'fe-ai-search' ) : (string) (int) $times_activated_max;
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
					esc_html__( 'Activation count: %1$s / %2$s', 'fe-ai-search' ),
					esc_html( (string) ( $times_activated ?? 0 ) ),
					esc_html( $max_text )
				);
				?>
			</p>

		<?php else : ?>

			<button
				type="button"
				id="fe_ai_search_license_activate"
				class="button button-primary"
				data-license-product-id="<?php echo esc_attr( (string) FE_AI_Search_License::PRODUCT_ID_PRO ); ?>"
				data-license-input-id="fe_ai_search_license_key_input"
				data-license-action="activate"
			>
				<?php esc_html_e( 'Activate', 'fe-ai-search' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Enter the license key you received at the time of purchase and press the "Activate" button.', 'fe-ai-search' ); ?>
			</p>

			<?php
			$error = get_transient( 'fe_ai_search_license_error' );
			if ( $error ) :
				?>
				<p style="color: red;"><?php echo esc_html( $error ); ?></p>
				<?php
				delete_transient( 'fe_ai_search_license_error' );
			endif;
			?>

		<?php endif; ?>

		<hr>
		<p>
			<strong><?php esc_html_e( 'Pinecone Add-on License (Test Product)', 'fe-ai-search' ); ?></strong>
		</p>
		<input
			type="password"
			autocomplete="off"
			id="fe_ai_search_license_key_input_pine"
			name="fe_ai_search_license_key_input_pine"
			value="<?php echo esc_attr( $license_key_pine ); ?>"
			class="regular-text"
			style="width: 300px;"
		>
		<button
			type="button"
			id="fe_ai_search_license_toggle_visibility_pine"
			class="button button-secondary fe-ai-search-license-toggle"
			data-target-input-id="fe_ai_search_license_key_input_pine"
			style="margin-left: 8px;"
		>
			<?php esc_html_e( 'Show', 'fe-ai-search' ); ?>
		</button>
		<?php if ( 'active' === $license_status_pine ) : ?>
			<button
				type="button"
				id="fe_ai_search_license_deactivate_pine"
				class="button button-secondary"
				data-license-product-id="<?php echo esc_attr( (string) FE_AI_Search_License::PRODUCT_ID_PINECONE ); ?>"
				data-license-input-id="fe_ai_search_license_key_input_pine"
				data-license-action="deactivate"
			>
				<?php esc_html_e( 'Deactivate', 'fe-ai-search' ); ?>
			</button>
			<p class="description" style="color: green; font-weight: bold;">
				<?php esc_html_e( 'The Pinecone add-on license is valid.', 'fe-ai-search' ); ?>
			</p>
			<?php if ( ! class_exists( 'FE_AI_Search_Pinecone_Addon' ) ) : ?>
				<p class="description" style="color: #dc2626;">
					<?php esc_html_e( 'The Pinecone add-on plugin is not installed or not active. Please install and activate the Pinecone add-on to use Pinecone features.', 'fe-ai-search' ); ?>
				</p>
			<?php endif; ?>
			<?php
				$data_pine                = isset( $entry_pine['data'] ) && is_array( $entry_pine['data'] ) ? $entry_pine['data'] : [];
				$times_activated_pine     = $data_pine['timesActivated'] ?? null;
				$times_activated_max_pine = $data_pine['timesActivatedMax'] ?? null;
				$max_text_pine            = is_null( $times_activated_max_pine ) ? __( 'Unlimited', 'fe-ai-search' ) : (string) (int) $times_activated_max_pine;
			?>
			<p class="description">
				<?php
					/* translators: 1: times activated, 2: max activations */
					printf(
						esc_html__( 'Activation count: %1$s / %2$s', 'fe-ai-search' ),
						esc_html( (string) ( $times_activated_pine ?? 0 ) ),
						esc_html( $max_text_pine )
					);
				?>
			</p>
		<?php else : ?>
			<button
				type="button"
				id="fe_ai_search_license_activate_pine"
				class="button button-primary"
				data-license-product-id="<?php echo esc_attr( (string) FE_AI_Search_License::PRODUCT_ID_PINECONE ); ?>"
				data-license-input-id="fe_ai_search_license_key_input_pine"
				data-license-action="activate"
			>
				<?php esc_html_e( 'Activate', 'fe-ai-search' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Enter the license key for the Pinecone add-on and press the "Activate" button.', 'fe-ai-search' ); ?>
			</p>
		<?php endif; ?>

		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<?php
	}
}
