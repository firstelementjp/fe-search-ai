<?php
/**
 * License helper for FE AI Search.
 *
 * @package    fe-search-ai
 * @subpackage Core
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License helper for FE AI Search.
 *
 * Handles license validation and product ID management for paid add-ons.
 */
class FE_Search_AI_License {
	/**
	 * Product IDs for each paid add-on.
	 *
	 * These IDs are provisional and should be kept in sync with the
	 * WooCommerce products used by the license server.
	 */
	public const PRODUCT_ID_PRO = 4328;

	/**
	 * Product IDs that unlock paid Pro features.
	 */
	public const PRODUCT_IDS_PAID = [
		self::PRODUCT_ID_PRO,
		4330,
		4335,
		4337,
	];

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
	 * Returns paid product IDs.
	 *
	 * @return int[]
	 */
	public static function get_paid_product_ids() {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', self::PRODUCT_IDS_PAID ),
					static function ( $product_id ) {
						return $product_id > 0;
					}
				)
			)
		);
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

		foreach ( self::get_paid_product_ids() as $paid_product_id ) {
			if ( isset( $products[ $paid_product_id ] ) && isset( $products[ $paid_product_id ]['status'] ) && 'active' === (string) $products[ $paid_product_id ]['status'] ) {
				$entry      = $products[ $paid_product_id ];
				$product_id = $paid_product_id;
				break;
			}
		}

		foreach ( self::get_paid_product_ids() as $paid_product_id ) {
			if ( isset( $products[ $paid_product_id ] ) ) {
				$entry      = $products[ $paid_product_id ];
				$product_id = $paid_product_id;
				break;
			}
		}

		if ( ! isset( $entry ) ) {
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
		$products = self::get_products();
		if ( ! isset( $products[ $product_id ] ) ) {
			return false;
		}

		$status = isset( $products[ $product_id ]['status'] ) ? (string) $products[ $product_id ]['status'] : 'inactive';

		return ( 'active' === $status );
	}

	/**
	 * Checks whether any paid product license is active.
	 *
	 * @return bool
	 */
	public static function is_any_paid_product_active() {
		foreach ( self::get_paid_product_ids() as $product_id ) {
			if ( self::is_product_active( $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the first stored paid product entry.
	 *
	 * @return array{
	 *     product_id: int,
	 *     key: string,
	 *     status: string,
	 *     data: array
	 * }
	 */
	public static function get_paid_product_entry() {
		$products = self::get_products();

		foreach ( self::get_paid_product_ids() as $product_id ) {
			if ( ! isset( $products[ $product_id ] ) || ! is_array( $products[ $product_id ] ) ) {
				continue;
			}

			$entry  = $products[ $product_id ];
			$status = isset( $entry['status'] ) ? (string) $entry['status'] : 'inactive';
			if ( 'active' !== $status ) {
				continue;
			}

			return [
				'product_id' => $product_id,
				'key'        => isset( $entry['key'] ) ? (string) $entry['key'] : '',
				'status'     => $status,
				'data'       => isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : [],
			];
		}

		foreach ( self::get_paid_product_ids() as $product_id ) {
			if ( ! isset( $products[ $product_id ] ) || ! is_array( $products[ $product_id ] ) ) {
				continue;
			}

			$entry = $products[ $product_id ];

			return [
				'product_id' => $product_id,
				'key'        => isset( $entry['key'] ) ? (string) $entry['key'] : '',
				'status'     => isset( $entry['status'] ) ? (string) $entry['status'] : 'inactive',
				'data'       => isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : [],
			];
		}

		return [
			'product_id' => self::PRODUCT_ID_PRO,
			'key'        => '',
			'status'     => 'inactive',
			'data'       => [],
		];
	}

	/**
	 * Convenience wrapper for checking the Pro license status.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return self::is_any_paid_product_active();
	}
}
