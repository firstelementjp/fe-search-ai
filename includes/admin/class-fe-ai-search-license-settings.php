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
 */

namespace FEAISearch\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the UI and registration for the main license settings tab.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
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
		 * Check the status of the license
		 */
		$license_data = $this->options['license'] ?? [];
		$status       = $license_data['status'] ?? 'inactive';
		$products     = $license_data['data']['products'] ?? [];

		$this->is_license_active = ( 'active' === $status && in_array( 'pro', $products, true ) );

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
		$icon = '';
		if ( ! $this->is_license_active ) {
			$icon = '<span class="dashicons dashicons-warning" style="color: #f59e0b;"></span>';
		}
		echo '<a href="#tab-license" class="nav-tab">' . esc_html__( 'License', 'fe-ai-search' ) . ' ' . wp_kses_post( $icon ) . '</a>';
	}

	/**
	 * Renders the HTML content wrapper for the "License" tab.
	 *
	 * @since 1.0.0
	 */
	public function render_content() {
		?>
		<div id="tab-license" class="tab-content">
			<table class="form-table">
				<?php
				do_settings_fields( 'fe-ai-search', 'fe_ai_search_license_section' );
				?>
			</table>
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
		$license_data = isset( $this->options['license'] ) ? $this->options['license'] : [];

		$license_key    = isset( $license_data['key'] ) ? $license_data['key'] : '';
		$license_status = isset( $license_data['status'] ) ? $license_data['status'] : 'inactive';
		?>
		<input
			type="text"
			id="fe_ai_search_license_key_input"
			name="fe_ai_search_settings[license][key]"
			value="<?php echo esc_attr( $license_key ); ?>"
			class="regular-text"
			style="width: 300px;"
		>

		<?php if ( 'active' === $license_status ) : ?>

			<button type="button" id="fe_ai_search_license_deactivate" class="button button-secondary"><?php esc_html_e( 'Deactivate', 'fe-ai-search' ); ?></button>
			<p class="description" style="color: green; font-weight: bold;">✔ <?php esc_html_e( 'The license is valid.', 'fe-ai-search' ); ?></p>

		<?php else : ?>

			<button type="button" id="fe_ai_search_license_activate" class="button button-primary"><?php esc_html_e( 'Activate', 'fe-ai-search' ); ?></button>
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

		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<?php
	}
}
