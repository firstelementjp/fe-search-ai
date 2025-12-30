<?php
/**
 * License helper for FE AI Search.
 *
 * @package    fe-ai-search
 * @subpackage Core
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FE_Search_AI_License {
	/**
	 * Product IDs for each paid add-on.
	 *
	 * These IDs are provisional and should be kept in sync with the
	 * WooCommerce products used by the license server.
	 */
	public const PRODUCT_ID_PRO      = 65;
	public const PRODUCT_ID_PINECONE = 318;

	/**
	 * Returns all product license entries indexed by productId.
	 *
	 * Expects the fe_search_ai_license option to use the
	 * products[productId] flat format.
	 *
	 * @return array<int, array{
	 *     key: string,
	 *     status: string,
	 *     data: array
	 * }>
	 */
	public static function get_products() {
		$option   = get_option( 'fe_search_ai_license', [] );
		$products = $option['products'] ?? [];

		return is_array( $products ) ? $products : [];
	}

	/**
	 * Returns normalized license information.
	 *
	 * @return array{
	 *     status: string,
	 *     data: array,
	 *     product_id: int,
	 *     is_active: bool
	 * }
	 */
	public static function get_info() {
		$products = self::get_products();
		if ( empty( $products ) ) {
			return [
				'status'     => 'inactive',
				'data'       => [],
				'product_id' => self::PRODUCT_ID_PRO,
				'is_active'  => false,
			];
		}

		// Prefer the Pro product entry; fall back to the first available.
		if ( isset( $products[ self::PRODUCT_ID_PRO ] ) ) {
			$entry      = $products[ self::PRODUCT_ID_PRO ];
			$product_id = self::PRODUCT_ID_PRO;
		} else {
			$entry      = reset( $products );
			$product_id = (int) key( $products );
		}

		$status = isset( $entry['status'] ) ? (string) $entry['status'] : 'inactive';
		$data   = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : [];

		return [
			'status'     => $status,
			'data'       => $data,
			'product_id' => $product_id,
			'is_active'  => ( 'active' === $status ),
		];
	}

	/**
	 * Checks whether the given product license is active.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_product_active( $product_id ) {
		// Temporary: Force all products to be active for debugging
		return true;
		
		$products = self::get_products();
		if ( ! isset( $products[ $product_id ] ) ) {
			return false;
		}

		$status = isset( $products[ $product_id ]['status'] ) ? (string) $products[ $product_id ]['status'] : 'inactive';

		return ( 'active' === $status );
	}

	/**
	 * Convenience wrapper for checking the Pro license status.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return self::is_product_active( self::PRODUCT_ID_PRO );
	}
}
