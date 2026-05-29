<?php
/**
 * Dokan multi-vendor compatibility. When the widget is rendered inside a
 * Dokan vendor store page we automatically scope the query to that vendor.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-scope to vendor when inside Dokan store context.
 */
class Compat_Dokan {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! function_exists( 'dokan_is_store_page' ) && ! class_exists( '\WeDevs_Dokan' ) ) {
			return;
		}
		add_filter( 'pag_query_args', [ $this, 'scope_to_vendor' ], 10, 3 );
	}

	/**
	 * Inject author=vendor_id when on a vendor store page if not already set.
	 *
	 * @param array $args     WP_Query args.
	 * @param array $settings Widget settings.
	 * @param int   $page     Page.
	 * @return array
	 */
	public function scope_to_vendor( $args, $settings, $page ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! empty( $args['author'] ) ) {
			return $args;
		}
		if ( function_exists( 'dokan_is_store_page' ) && dokan_is_store_page() ) {
			$store_user = function_exists( 'dokan_get_store_user' ) ? dokan_get_store_user( get_query_var( 'author' ) ) : null;
			if ( $store_user && method_exists( $store_user, 'get_id' ) ) {
				$args['author'] = (int) $store_user->get_id();
			}
		}
		return $args;
	}

	/**
	 * Resolve a Dokan store name for a vendor user ID, fallback to display name.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return array{name:string, url:string}|null
	 */
	public static function get_vendor_info( $vendor_id ) {
		$vendor_id = absint( $vendor_id );
		if ( ! $vendor_id ) {
			return null;
		}
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$info = dokan_get_store_info( $vendor_id );
			$name = ! empty( $info['store_name'] ) ? (string) $info['store_name'] : '';
			$url  = function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $vendor_id ) : '';
			if ( $name ) {
				return [ 'name' => $name, 'url' => $url ];
			}
		}
		$user = get_userdata( $vendor_id );
		if ( ! $user ) {
			return null;
		}
		return [ 'name' => $user->display_name, 'url' => '' ];
	}
}
