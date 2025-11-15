<?php
/**
 * Handles communication with the license server for the Pro add-on.
 *
 * This class is responsible for sending activation and deactivation requests
 * to the licensing server to validate the user's Pro license key.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.0.0
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The license handler class for the Pro add-on.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Core
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_License_Handler {

	/**
	 * Activates a license key by sending it to the license server.
	 *
	 * @since 1.0.0
	 * @param string $license_key The license key to activate.
	 * @return array An array containing the result of the activation attempt.
	 */
	public function activate( string $license_key ): array {
		return $this->send_request( 'activate', $license_key );
	}

	/**
	 * Deactivates a license key by sending it to the license server.
	 *
	 * @since 1.0.0
	 * @param string $license_key The license key to deactivate.
	 * @return array An array containing the result of the deactivation attempt.
	 */
	public function deactivate( string $license_key ): array {
		return $this->send_request( 'deactivate', $license_key );
	}

	/**
	 * Sends a request to the license server.
	 *
	 * This is a common private method used for both activation and deactivation.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param string $action      The action to perform ('activate' or 'deactivate').
	 * @param string $license_key The license key.
	 * @return array An array containing the result of the request.
	 */
	private function send_request( string $action, string $license_key ): array {
		if ( empty( $license_key ) ) {
			return [ 'success' => false, 'message' => __( 'The license key has not been entered.', 'fe-ai-search' ) ];
		}

		// Build the API URL for the license server.
		$api_url = FE_AI_SEARCH_PRO_STORE_URL . '/wp-json/lmfwc/v2/licenses/' . $action;

		$response = wp_remote_post(
			$api_url,
			[
				'timeout' => 20,
				'body'    => [
					'license_key' => $license_key,
					'instance'    => home_url(),
					'product_id'  => FE_AI_SEARCH_PRO_PRODUCT_ID,
				],
			]
		);

		// Handle connection errors.
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => __( 'Could not connect to the license server', 'fe-ai-search' ) . ': ' . $response->get_error_message() ];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Handle invalid responses from the server.
		if ( wp_remote_retrieve_response_code( $response ) >= 400 || empty( $data ) || ! isset( $data['success'] ) ) {
			$error_message = $data['message'] ?? __( 'An invalid response was received from the license server.', 'fe-ai-search' );
			return [ 'success' => false, 'message' => $error_message ];
		}

		// Return a formatted result array.
		return [
			'success' => $data['success'],
			'message' => $data['message'] ?? ( $data['success'] ? __( 'The operation was successful.', 'fe-ai-search' ) : __( 'The operation failed.', 'fe-ai-search' ) ),
			'status'  => ( $data['success'] && ( $data['data']['activated'] ?? false ) ) ? 'active' : 'inactive',
		];
	}
}
