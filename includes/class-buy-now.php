<?php
/**
 * Buy Now module.
 *
 * Flow:
 *   1. Click → POST /buy-now { product_id, variation_id?, attributes? }
 *   2. Server:
 *        a. Snapshot the existing cart (cart_contents minus items being added).
 *        b. Push snapshot onto a stack stored in user_meta (or WC session for
 *           guests). Each snapshot = { id, time, original_items, buy_now_pid,
 *           buy_now_vid }.
 *        c. add_to_cart() the buy-now product.
 *        d. Return { checkout_url, snapshot_id }.
 *   3. Client redirects to checkout.
 *   4. On `woocommerce_thankyou`: pop the matching snapshot for the most recent
 *      placed order; re-add the snapshot's original_items so they aren't lost
 *      with WC's cart-empty step.
 *   5. On `pagehide` (sendBeacon → /buy-now/abandon): no destructive action;
 *      cart already contains [original + buy_now]. We touch the snapshot stack
 *      to update the TTL.
 *
 * Storage:
 *   - logged-in: user_meta `pag_buy_now_snapshots` (array)
 *   - guest:     WC()->session 'pag_buy_now_snapshots'
 *
 * Stale snapshots are pruned on every read (TTL = 24h).
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Buy Now logic.
 */
class Buy_Now {

	const META_KEY  = 'pag_buy_now_snapshots';
	const TTL       = 86400; // 24 hours.

	/** @var REST_API */
	private $rest_api;

	/** @var Rate_Limiter */
	private $rate_limiter;

	/**
	 * @param REST_API     $rest_api    REST router.
	 * @param Rate_Limiter $rate_limiter Limiter.
	 */
	public function __construct( REST_API $rest_api, Rate_Limiter $rate_limiter ) {
		$this->rest_api     = $rest_api;
		$this->rate_limiter = $rate_limiter;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'woocommerce_thankyou', [ $this, 'on_thankyou' ], 10, 1 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 25 );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			PAG_REST_NAMESPACE,
			'/buy-now',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_buy_now' ],
				'permission_callback' => [ $this->rest_api, 'permission_public' ],
				'args'                => [
					'product_id'   => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'variation_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'attributes'   => [
						'required' => false,
						'type'     => 'object',
						'default'  => [],
					],
					'quantity'     => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			PAG_REST_NAMESPACE,
			'/buy-now/abandon',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_abandon' ],
				'permission_callback' => [ $this->rest_api, 'permission_public' ],
			]
		);
	}

	/**
	 * Enqueue Buy Now JS (depends on widget.js + modal.js).
	 */
	public function enqueue() {
		wp_enqueue_script( 'pag-buy-now' );
	}

	// =========================================================================
	// Storage helpers
	// =========================================================================

	/**
	 * Read the snapshot stack and prune expired entries.
	 *
	 * @return array
	 */
	private function get_stack() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$stack = get_user_meta( $user_id, self::META_KEY, true );
		} else {
			$stack = function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::META_KEY ) : null;
		}
		if ( ! is_array( $stack ) ) {
			$stack = [];
		}

		$now    = time();
		$pruned = [];
		foreach ( $stack as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$age = $now - (int) ( $entry['time'] ?? 0 );
			if ( $age >= 0 && $age <= self::TTL ) {
				$pruned[] = $entry;
			}
		}

		// Save the pruned set back so we don't keep re-pruning.
		if ( count( $pruned ) !== count( $stack ) ) {
			$this->save_stack( $pruned );
		}
		return $pruned;
	}

	/**
	 * Persist the snapshot stack.
	 *
	 * @param array $stack New stack.
	 */
	private function save_stack( array $stack ) {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_KEY, $stack );
			return;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::META_KEY, $stack );
		}
	}

	// =========================================================================
	// REST handlers
	// =========================================================================

	/**
	 * Handle a Buy Now click.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_buy_now( $request ) {
		$rl = $this->rate_limiter->check( 'buy_now' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error(
				'pag_no_wc',
				__( 'WooCommerce is not active.', 'product-archive-grid' ),
				[ 'status' => 500 ]
			);
		}

		// WC()->cart isn't auto-initialised in REST/AJAX request contexts (only
		// on front-end page loads via wc_init_frontend_default_hooks). Without
		// this call WC()->cart is null and the legacy guard below returned
		// "WooCommerce not available" even though WC was running fine. This
		// is the documented WC idiom for loading the cart from REST/AJAX and
		// is a no-op when the cart is already loaded.
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		// Defensive: on some hosts (Pantheon edge cache, Hostinger Business
		// with certain PHP-FPM configs) WC()->session can remain null after
		// wc_load_cart() in a REST context, which then leaves WC()->cart null
		// too. Force-init the session handler ourselves before the cart guard
		// below runs.
		if ( null === WC()->session && class_exists( 'WC_Session_Handler' ) ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
			if ( null === WC()->cart ) {
				WC()->initialize_cart();
			}
		}

		if ( ! WC()->cart ) {
			return new \WP_Error(
				'pag_cart_unavailable',
				__( 'Cart could not be initialized.', 'product-archive-grid' ),
				[ 'status' => 500 ]
			);
		}

		$product_id   = (int) $request->get_param( 'product_id' );
		$variation_id = (int) $request->get_param( 'variation_id' );
		$quantity     = max( 1, (int) $request->get_param( 'quantity' ) );

		$product = Security::get_validated_product( $product_id );
		if ( ! $product || ! $product->is_visible() ) {
			return new \WP_Error(
				'pag_invalid_product',
				__( 'Invalid product.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! $product->is_purchasable() ) {
			return new \WP_Error(
				'pag_not_purchasable',
				__( 'This product is not purchasable.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}

		// Validate variation if provided.
		$variation_attrs = [];
		if ( $product->is_type( 'variable' ) ) {
			if ( ! $variation_id ) {
				return new \WP_Error(
					'pag_missing_variation',
					__( 'Please choose product options before continuing.', 'product-archive-grid' ),
					[ 'status' => 400 ]
				);
			}
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || $variation->get_parent_id() !== $product->get_id() ) {
				return new \WP_Error(
					'pag_invalid_variation',
					__( 'Invalid variation.', 'product-archive-grid' ),
					[ 'status' => 400 ]
				);
			}
			if ( ! $variation->is_in_stock() ) {
				return new \WP_Error(
					'pag_out_of_stock',
					__( 'Selected variation is out of stock.', 'product-archive-grid' ),
					[ 'status' => 400 ]
				);
			}

			$raw_attrs = (array) $request->get_param( 'attributes' );
			foreach ( $raw_attrs as $k => $v ) {
				$variation_attrs[ wc_clean( (string) $k ) ] = wc_clean( (string) $v );
			}
		} elseif ( ! $product->is_in_stock() ) {
			return new \WP_Error(
				'pag_out_of_stock',
				__( 'This product is out of stock.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}

		// Snapshot the existing cart BEFORE adding the buy-now item.
		$original_items = $this->snapshot_cart_items();

		$cart_item_key = WC()->cart->add_to_cart(
			$product->get_id(),
			$quantity,
			$variation_id,
			$variation_attrs
		);

		if ( ! $cart_item_key ) {
			$message = __( 'Could not add product to cart.', 'product-archive-grid' );
			if ( function_exists( 'wc_get_notices' ) ) {
				$err = wc_get_notices( 'error' );
				if ( ! empty( $err ) ) {
					$first   = reset( $err );
					$message = is_array( $first ) && ! empty( $first['notice'] )
						? wp_strip_all_tags( $first['notice'] )
						: $message;
				}
				wc_clear_notices();
			}
			return new \WP_Error( 'pag_buy_now_failed', $message, [ 'status' => 400 ] );
		}

		// Push snapshot.
		$snapshot_id = wp_generate_uuid4();
		$stack       = $this->get_stack();
		$stack[]     = [
			'id'             => $snapshot_id,
			'time'           => time(),
			'buy_now_pid'    => $product->get_id(),
			'buy_now_vid'    => $variation_id,
			'cart_item_key'  => $cart_item_key,
			'original_items' => $original_items,
		];
		$this->save_stack( $stack );

		return rest_ensure_response(
			[
				'success'      => true,
				'snapshot_id'  => $snapshot_id,
				'checkout_url' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' ),
				'cart_count'   => WC()->cart->get_cart_contents_count(),
			]
		);
	}

	/**
	 * sendBeacon endpoint. We use this to refresh the TTL on the latest snapshot
	 * so a user who navigates away from checkout and returns later still gets
	 * their cart restored properly. We do NOT mutate the cart here.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_abandon( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rl = $this->rate_limiter->check( 'buy_now_abandon' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}
		// Read = prune side-effect; nothing else needed.
		$this->get_stack();
		return rest_ensure_response( [ 'success' => true ] );
	}

	// =========================================================================
	// Cart snapshot helpers
	// =========================================================================

	/**
	 * Capture the current cart contents in a re-addable form.
	 *
	 * @return array
	 */
	private function snapshot_cart_items() {
		$out = [];
		if ( ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$out[] = [
				'product_id'     => (int) $item['product_id'],
				'variation_id'   => (int) ( $item['variation_id'] ?? 0 ),
				'variation'      => isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : [],
				'quantity'       => (int) $item['quantity'],
				'cart_item_data' => [], // Skip 3rd-party meta on restore (best-effort).
			];
		}
		return $out;
	}

	// =========================================================================
	// Thankyou hook → restore originals
	// =========================================================================

	/**
	 * After a successful checkout, find the matching snapshot (whose
	 * buy_now_pid is in the order) and restore the original cart items.
	 *
	 * @param int $order_id Placed order ID.
	 */
	public function on_thankyou( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip if we already processed this order in this session.
		$flag_key = 'pag_bn_processed_' . $order_id;
		if ( WC()->session && WC()->session->get( $flag_key ) ) {
			return;
		}
		if ( WC()->session ) {
			WC()->session->set( $flag_key, 1 );
		}

		$ordered_pids = [];
		foreach ( $order->get_items() as $line ) {
			$pid = (int) $line->get_product_id();
			if ( $pid ) {
				$ordered_pids[] = $pid;
			}
		}
		if ( empty( $ordered_pids ) ) {
			return;
		}

		$stack = $this->get_stack();
		if ( empty( $stack ) ) {
			return;
		}

		// Walk LIFO and pop the latest snapshot whose buy_now_pid is in the order.
		$matched_index = null;
		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			$pid = (int) ( $stack[ $i ]['buy_now_pid'] ?? 0 );
			if ( $pid && in_array( $pid, $ordered_pids, true ) ) {
				$matched_index = $i;
				break;
			}
		}
		if ( null === $matched_index ) {
			return;
		}

		$snapshot = $stack[ $matched_index ];
		array_splice( $stack, $matched_index, 1 );
		$this->save_stack( $stack );

		// Re-add original items into the cart. The cart was emptied by WC during
		// the checkout submit step; we restore so the user keeps their pre-Buy
		// Now items intact.
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( (array) ( $snapshot['original_items'] ?? [] ) as $it ) {
			$pid = (int) ( $it['product_id'] ?? 0 );
			$qty = max( 1, (int) ( $it['quantity'] ?? 1 ) );
			$vid = (int) ( $it['variation_id'] ?? 0 );
			$var = is_array( $it['variation'] ?? null ) ? $it['variation'] : [];
			if ( ! $pid ) {
				continue;
			}
			$prod = wc_get_product( $vid ?: $pid );
			if ( ! $prod || ! $prod->is_in_stock() ) {
				continue;
			}
			WC()->cart->add_to_cart( $pid, $qty, $vid, $var );
		}
	}
}
