<?php
/**
 * REST API registration. All PAG endpoints live under /wp-json/pag/v1/.
 * Sub-systems (cart, wishlist, quick view, buy now) call register_route()
 * to attach themselves while sharing the rate limiter and security helpers.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * REST router with built-in nonce + rate-limit middleware.
 */
class REST_API {

	/** @var Rate_Limiter */
	private $rate_limiter;

	/**
	 * @param Rate_Limiter $rate_limiter Limiter dependency.
	 */
	public function __construct( Rate_Limiter $rate_limiter ) {
		$this->rate_limiter = $rate_limiter;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the always-on routes (cart-related). Other classes hook into
	 * 'rest_api_init' independently to add their own routes.
	 */
	public function register_routes() {
		register_rest_route(
			PAG_REST_NAMESPACE,
			'/add-to-cart',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_add_to_cart' ],
				'permission_callback' => [ $this, 'permission_public' ],
				'args'                => [
					'product_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'quantity'   => [
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
			'/load-more',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_load_more' ],
				'permission_callback' => [ $this, 'permission_public' ],
				'args'                => [
					'page'     => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 2,
						'sanitize_callback' => 'absint',
					],
					'settings' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Public permission gate that still requires a valid nonce. We accept any
	 * visitor (logged-in or not) but block requests that lack our nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permission_public( $request ) {
		if ( ! Security::verify_request( $request ) ) {
			return new \WP_Error(
				'pag_invalid_nonce',
				__( 'Invalid or missing security token.', 'product-archive-grid' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Permission gate for endpoints that require a logged-in user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permission_logged_in( $request ) {
		$base = $this->permission_public( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'pag_login_required',
				__( 'You must be logged in for this action.', 'product-archive-grid' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	/**
	 * Apply the rate limiter and short-circuit on failure.
	 *
	 * @param string $action Logical action key.
	 * @return true|\WP_Error
	 */
	public function rate_limit( $action ) {
		return $this->rate_limiter->check( $action );
	}

	// -----------------------------------------------------------------------
	// Built-in handlers
	// -----------------------------------------------------------------------

	/**
	 * AJAX add-to-cart handler. Only simple/in-stock products are added here;
	 * variable products redirect on the frontend instead.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_add_to_cart( $request ) {
		$rl = $this->rate_limit( 'add_to_cart' );
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

		if ( ! WC()->cart ) {
			return new \WP_Error(
				'pag_cart_unavailable',
				__( 'Cart could not be initialized.', 'product-archive-grid' ),
				[ 'status' => 500 ]
			);
		}

		$product_id = (int) $request->get_param( 'product_id' );
		$quantity   = max( 1, (int) $request->get_param( 'quantity' ) );

		$product = Security::get_validated_product( $product_id );
		if ( ! $product ) {
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

		// Variable products must select a variation on the product page.
		if ( $product->is_type( 'variable' ) ) {
			return new \WP_Error(
				'pag_variable_redirect',
				__( 'Please choose options on the product page.', 'product-archive-grid' ),
				[
					'status'       => 400,
					'redirect_url' => $product->get_permalink(),
				]
			);
		}

		if ( ! $product->is_in_stock() ) {
			return new \WP_Error(
				'pag_out_of_stock',
				__( 'This product is out of stock.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}

		// Respect catalog visibility.
		if ( ! $product->is_visible() ) {
			return new \WP_Error(
				'pag_invalid_product',
				__( 'Invalid product.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}

		$cart_item_key = WC()->cart->add_to_cart( $product->get_id(), $quantity );
		if ( ! $cart_item_key ) {
			$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : [];
			$message = __( 'Could not add product to cart.', 'product-archive-grid' );
			if ( ! empty( $notices ) ) {
				$first   = reset( $notices );
				$message = is_array( $first ) && ! empty( $first['notice'] ) ? wp_strip_all_tags( $first['notice'] ) : $message;
			}
			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}
			return new \WP_Error( 'pag_add_failed', $message, [ 'status' => 400 ] );
		}

		// Generate cart fragments for live mini-cart sync.
		$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', [] );

		return rest_ensure_response(
			[
				'success'       => true,
				'product_id'    => $product->get_id(),
				'cart_count'    => WC()->cart->get_cart_contents_count(),
				'cart_total'    => WC()->cart->get_cart_total(),
				'cart_hash'     => WC()->cart->get_cart_hash(),
				'fragments'     => $fragments,
				'cart_item_key' => $cart_item_key,
				'message'       => sprintf(
					/* translators: %s product name */
					__( '"%s" added to cart.', 'product-archive-grid' ),
					$product->get_name()
				),
			]
		);
	}

	/**
	 * Load-more handler — returns rendered HTML for the next page of results.
	 *
	 * The 'settings' param is a JSON blob serialised by the widget on render
	 * (kept compact). It contains everything Query needs to rebuild the same
	 * query for page N+1.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_load_more( $request ) {
		$rl = $this->rate_limit( 'load_more' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$page = max( 1, (int) $request->get_param( 'page' ) );

		$settings_raw = (string) $request->get_param( 'settings' );
		$settings     = json_decode( $settings_raw, true );
		if ( ! is_array( $settings ) ) {
			return new \WP_Error(
				'pag_invalid_settings',
				__( 'Invalid pagination payload.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}

		// Re-sanitize settings before use; never trust client input.
		$settings = Query::sanitize_settings( $settings );

		$query = Query::build( $settings, $page );

		ob_start();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				Template::render_card( get_the_ID(), $settings );
			}
		}
		wp_reset_postdata();
		$html = ob_get_clean();

		return rest_ensure_response(
			[
				'success'      => true,
				'html'         => $html,
				'has_more'     => $page < (int) $query->max_num_pages,
				'next_page'    => $page + 1,
				'total_pages'  => (int) $query->max_num_pages,
			]
		);
	}
}
