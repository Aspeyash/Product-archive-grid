<?php
/**
 * Security helpers. Centralise sanitisation, escaping, and request validation.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper class. No state.
 */
final class Security {

	/** Nonce action used for all PAG REST + AJAX requests. */
	const NONCE_ACTION = 'pag_rest';

	/**
	 * Create a nonce for our REST API.
	 *
	 * IMPORTANT: We deliberately use the `wp_rest` action (not `pag_rest`)
	 * even though our verify_request() accepts either. WordPress core's
	 * rest_cookie_check_errors() runs before any plugin permission_callback
	 * and validates X-WP-Nonce specifically against the `wp_rest` action.
	 * If the nonce we send doesn't validate there, core returns
	 * "Cookie check failed" with a 403 and our REST handler is never reached
	 * — affecting every logged-in user (admins included).
	 *
	 * Generating against `wp_rest` makes core happy AND still passes our
	 * own verify_request() check (which accepts wp_rest as a fallback).
	 *
	 * @return string
	 */
	public static function nonce() {
		return wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Verify a request's nonce. Looks at common locations: body, header, query.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function verify_request( $request ) {
		$nonce = '';

		if ( $request instanceof \WP_REST_Request ) {
			$nonce = (string) $request->get_header( 'x_wp_nonce' );
			if ( '' === $nonce ) {
				$nonce = (string) $request->get_param( '_wpnonce' );
			}
			if ( '' === $nonce ) {
				$nonce = (string) $request->get_param( 'nonce' );
			}
		}

		// REST cookie auth uses 'wp_rest' nonce. Accept either.
		if ( '' === $nonce ) {
			return false;
		}
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION )
			|| (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Best-effort client IP extraction. Honours common reverse-proxy headers but
	 * never trusts them blindly: falls back to REMOTE_ADDR when missing/invalid.
	 *
	 * @return string IPv4/IPv6 or '0.0.0.0'.
	 */
	public static function client_ip() {
		$candidates = [];
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP']; // phpcs:ignore
		}
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$candidates[] = $_SERVER['HTTP_X_REAL_IP']; // phpcs:ignore
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff          = (string) $_SERVER['HTTP_X_FORWARDED_FOR']; // phpcs:ignore
			$first        = trim( explode( ',', $xff )[0] );
			$candidates[] = $first;
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = (string) $_SERVER['REMOTE_ADDR']; // phpcs:ignore
		}

		foreach ( $candidates as $ip ) {
			$ip = trim( wp_unslash( (string) $ip ) );
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Stable identifier per user-agent + IP. Used as the rate-limit key when no
	 * authenticated user ID is available.
	 *
	 * @return string Hashed token (40 chars).
	 */
	public static function visitor_signature() {
		$ip = self::client_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore
		return sha1( $ip . '|' . substr( $ua, 0, 200 ) );
	}

	/**
	 * Sanitise a list of integer IDs from mixed input.
	 *
	 * @param mixed $raw String, array, or null.
	 * @return int[]
	 */
	public static function sanitize_id_list( $raw ) {
		if ( is_string( $raw ) ) {
			$parts = array_map( 'trim', explode( ',', $raw ) );
		} elseif ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			return [];
		}

		$parts = array_map( 'absint', $parts );
		$parts = array_values( array_filter( $parts ) );
		// Hard cap to prevent abuse.
		return array_slice( $parts, 0, 200 );
	}

	/**
	 * Validate a single product ID and return the WC_Product or null.
	 *
	 * @param int $product_id Product ID.
	 * @return \WC_Product|null
	 */
	public static function get_validated_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->exists() ) {
			return null;
		}
		return $product;
	}

	/**
	 * Allow-list of HTML used when echoing inline SVG icons.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function svg_allowed_tags() {
		return [
			'svg'      => [
				'xmlns'   => true,
				'viewbox' => true,
				'fill'    => true,
				'width'   => true,
				'height'  => true,
				'class'   => true,
				'aria-hidden' => true,
				'focusable'   => true,
				'role'        => true,
			],
			'path'     => [
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'fill-rule'       => true,
				'clip-rule'       => true,
			],
			'circle'   => [
				'cx'           => true,
				'cy'           => true,
				'r'            => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
			],
			'rect'     => [
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
				'fill'   => true,
			],
			'g'        => [
				'fill'      => true,
				'stroke'    => true,
				'transform' => true,
			],
			'polyline' => [
				'points'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			],
		];
	}

	/**
	 * Render an inline SVG with safe escaping.
	 *
	 * @param string $svg SVG markup.
	 * @return string Escaped SVG.
	 */
	public static function kses_svg( $svg ) {
		return wp_kses( $svg, self::svg_allowed_tags() );
	}
}
