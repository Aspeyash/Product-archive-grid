<?php
/**
 * Per-IP / per-user rate limiter using the WordPress transient API. Lightweight,
 * dependency-free, sufficient for Elementor-widget AJAX endpoints.
 *
 * Each "bucket" is a counter scoped by:
 *   - action name  (e.g. add_to_cart, wishlist_toggle, buy_now)
 *   - user signature (visitor IP + UA hash, or user ID for logged-in users)
 *
 * The bucket lives for $window seconds and rejects after $max requests.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Simple sliding window (well, fixed-window) rate limiter.
 */
class Rate_Limiter {

	/**
	 * Default per-action limits. Overridable via filter `pag_rate_limits`.
	 *
	 * @var array<string, array{max:int, window:int}>
	 */
	private $limits = [
		'default'         => [ 'max' => 60, 'window' => 60 ],   // 60 req / min.
		'add_to_cart'     => [ 'max' => 30, 'window' => 60 ],   // 30 add-to-cart / min.
		'load_more'       => [ 'max' => 60, 'window' => 60 ],
		'wishlist_toggle' => [ 'max' => 60, 'window' => 60 ],
		'wishlist_get'    => [ 'max' => 120, 'window' => 60 ],
		'quick_view'      => [ 'max' => 60, 'window' => 60 ],
		'buy_now'         => [ 'max' => 15, 'window' => 60 ],
		'buy_now_abandon' => [ 'max' => 30, 'window' => 60 ],
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * Filter the rate limit table.
		 *
		 * @param array $limits Map of action => [max, window].
		 */
		$this->limits = (array) apply_filters( 'pag_rate_limits', $this->limits );
	}

	/**
	 * Check the limit and increment the counter atomically (best-effort).
	 *
	 * @param string $action Logical action (e.g. 'add_to_cart').
	 * @return true|\WP_Error True if allowed; WP_Error('pag_rate_limited', ...) if denied.
	 */
	public function check( $action ) {
		$action = sanitize_key( $action );
		$config = $this->limits[ $action ] ?? $this->limits['default'];
		$max    = max( 1, (int) $config['max'] );
		$window = max( 1, (int) $config['window'] );

		$key = $this->bucket_key( $action );

		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new \WP_Error(
				'pag_rate_limited',
				__( 'Too many requests. Please slow down and try again in a moment.', 'product-archive-grid' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Compose the transient key for the current visitor + action.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private function bucket_key( $action ) {
		$user_id = get_current_user_id();
		$id      = $user_id ? 'u' . $user_id : 'v' . Security::visitor_signature();
		return 'pag_rl_' . $action . '_' . $id;
	}

	/**
	 * Reset a bucket. Useful for tests; not used in normal flow.
	 *
	 * @param string $action Action name.
	 */
	public function reset( $action ) {
		delete_transient( $this->bucket_key( sanitize_key( $action ) ) );
	}
}
