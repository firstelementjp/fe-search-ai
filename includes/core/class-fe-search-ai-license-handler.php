<?php
/**
 * Handles communication with the license server for the Pro add-on.
 *
 * This class is responsible for sending activation and deactivation requests
 * to the licensing server to validate the user's Pro license key.
 *
 * @package    fe-search-ai
 * @subpackage Core
 * @since 0.9.0
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The license handler class for the Pro add-on.
 *
 * @since 0.9.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_License_Handler {

	/**
	 * Activates a license key by sending it to the license server.
	 *
	 * @since 0.9.0
	 * @param string $license_key The license key to activate.
	 * @return array An array containing the result of the activation attempt.
	 */
	public function activate( string $license_key ): array {
		return $this->send_request( 'activate', $license_key );
	}

	/**
	 * Deactivates a license key by sending it to the license server.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
	 * @access private
	 * @param string $action      The action to perform ('activate' or 'deactivate').
	 * @param string $license_key The license key.
	 * @return array An array containing the result of the request.
	 */
	private function send_request( string $action, string $license_key ): array {
		if ( empty( $license_key ) ) {
			return [ 'success' => false, 'message' => __( 'The license key has not been entered.', 'fe-search-ai' ) ];
		}

		$product_id        = (int) FE_Search_AI_License::PRODUCT_ID_PRO;
		$home_url          = home_url();
		$site_url          = site_url();
		$environment_type  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$is_non_production = $this->is_non_production_environment( $environment_type, [ $home_url, $site_url ] );

		// Call the vendor's proxy API instead of the LMFWC REST API directly so that
		// sensitive consumer keys remain on the server side only.
		if ( ! defined( 'FE_SEARCH_AI_LICENSE_API_URL' ) || empty( FE_SEARCH_AI_LICENSE_API_URL ) ) {
			return [ 'success' => false, 'message' => __( 'FE Search AI Pro is not installed or activated, so the license management feature is not available.', 'fe-search-ai' ) ];
		}

		$request_body = [
			'action'            => $action,
			'license_key'       => $license_key,
			'instance'          => $home_url,
			'home_url'          => $home_url,
			'site_url'          => $site_url,
			'product_id'        => $product_id,
			'productId'         => $product_id,
			'product_ids'       => implode( ',', FE_Search_AI_License::get_paid_product_ids() ),
			'productIds'        => wp_json_encode( FE_Search_AI_License::get_paid_product_ids() ),
			'environment_type'  => $environment_type,
			'is_non_production' => $is_non_production ? '1' : '0',
		];

		$response = wp_remote_post(
			FE_SEARCH_AI_LICENSE_API_URL,
			[
				'timeout' => 20,
				'body'    => $request_body,
			]
		);

		// Handle connection errors.
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => __( 'Could not connect to the license server', 'fe-search-ai' ) . ': ' . $response->get_error_message() ];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		// Handle invalid responses from the server.
		if ( $http_code >= 400 || empty( $data ) || ! isset( $data['success'] ) ) {
			$body_snippet  = is_string( $body ) ? substr( $body, 0, 300 ) : '';
			$error_message = $data['message'] ?? __( 'An invalid response was received from the license server.', 'fe-search-ai' );
			return [
				'success' => false,
				'message' => sprintf(
					'%s (HTTP %d) %s',
					(string) $error_message,
					$http_code,
					$body_snippet
				),
			];
		}

		$license_status = 'inactive';
		if ( ! empty( $data['success'] ) && ! empty( $data['data'] ) && isset( $data['data']['status'] ) ) {
			$license_status = ( 2 === (int) $data['data']['status'] ) ? 'active' : 'inactive';
		}

		return [
			'success' => $data['success'],
			'message' => $data['message'] ?? ( $data['success'] ? __( 'The operation was successful.', 'fe-search-ai' ) : __( 'The operation failed.', 'fe-search-ai' ) ),
			'status'  => $license_status,
			'data'    => $data['data'] ?? [],
		];
	}

	/**
	 * Determines whether the current site appears to be non-production.
	 *
	 * @param string $environment_type WordPress environment type.
	 * @param array  $urls             URLs to inspect.
	 * @return bool
	 */
	private function is_non_production_environment( string $environment_type, array $urls ): bool {
		if ( 'production' !== $environment_type ) {
			return true;
		}

		foreach ( $urls as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( ! is_string( $host ) || '' === $host ) {
				continue;
			}

			$host = strtolower( $host );
			if ( in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ) {
				return true;
			}

			if ( preg_match( '/(^|[.\\-])(staging|stage|test|dev|development|local)([.\\-]|$)/', $host ) ) {
				return true;
			}

			if ( preg_match( '/\\.(local|test|dev)$/', $host ) ) {
				return true;
			}
		}

		return false;
	}
}
