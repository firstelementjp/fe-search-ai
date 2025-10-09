<?php

namespace FEAISearch\Admin;

class FEAS_AI_License_Settings {

	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'feas_ai_settings_tabs', [ $this, 'add_tab' ] );
		add_action( 'feas_ai_settings_tabs_content', [ $this, 'render_content' ] );
	}

	public function add_tab() {
		$status = get_option( 'feas_ai_license_status', 'inactive' );
		$icon   = ( 'active' !== $status ) ? '<span class="dashicons dashicons-warning" style="color: #f59e0b;"></span>' : '';
		echo '<a href="#tab-license" class="nav-tab">' . esc_html__( 'License', 'fe-ai-search' ) . ' ' . $icon . '</a>';
	}

	public function render_content() {
		?>
		<div id="tab-license" class="tab-content">
			<table class="form-table">
				<?php do_settings_fields( 'fe-ai-search', 'feas_ai_license_section' ); ?>
			</table>
		</div>
		<?php
	}

	public function register_settings() {
		add_settings_section( 'feas_ai_license_section', __( 'License Activation', 'fe-ai-search' ), null, 'fe-ai-search' );
		register_setting( 'feas-ai-settings', 'feas_ai_license_key' );
		add_settings_field( 'feas_ai_license_key', __( 'License Key', 'fe-ai-search' ), [ $this, 'field_html' ], 'fe-ai-search', 'feas_ai_license_section' );
	}

	/**
	 * Renders the HTML for the license key input field
	 */

	public function field_html() {
		$license_key = get_option( 'feas_ai_pro_license_key' );
		$license_status = get_option( 'feas_ai_pro_license_status', 'inactive' );
		?>
		<input type="text" id="feas_ai_pro_license_key_input" name="feas_ai_pro_license_key" value="<?php echo esc_attr( $license_key ); ?>" class="regular-text" style="width: 300px;">

		<?php if ( $license_status === 'active' ) : ?>
			<button type="button" id="feas_ai_license_deactivate" class="button button-secondary"><?php esc_html_e( 'Disable', 'fe-ai-search-pro' ); ?></button>
			<p class="description" style="color: green; font-weight: bold;">✔ <?php esc_html_e( 'The license is valid.', 'fe-ai-search-pro' ); ?></p>
		<?php else: ?>
			<button
				type="button"
				id="feas_ai_license_activate"
				class="button button-primary"
				><?php esc_html_e( 'Activate', 'fe-ai-search-pro' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Enter the license key you received at the time of purchase and press the "Activate" button.', 'fe-ai-search-pro' ); ?>
			</p>
			<?php if ( $error = get_transient('feas_ai_license_error') ) : ?>
				<p style="color: red;"><?php echo esc_html( $error ); ?></p>
				<?php delete_transient('feas_ai_license_error'); ?>
			<?php endif; ?>
			<p class="description" style="color:red;">
				<?php esc_html_e( 'Some functions are not available because the license key is not authenticated or is invalid.', 'fe-ai-search-pro' ); ?>
			</p>
		<?php endif; ?>
		<span class="spinner" style="float: none; vertical-align: middle;"></span>
		<?php
	}
}
