<?php
/**
 * Asset registration. We only register; widgets enqueue on demand via
 * get_style_depends() / get_script_depends(). The Quick View / Wishlist /
 * Buy Now scripts are registered here too but only enqueued when the relevant
 * sub-system is active and a widget on the page needs them.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Asset wrangler.
 */
class Assets {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'register' ] );
		add_action( 'elementor/frontend/after_register_styles', [ $this, 'register' ] );
		add_action( 'elementor/frontend/after_register_scripts', [ $this, 'register' ] );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_editor' ] );
	}

	/**
	 * Register all stylesheets and scripts.
	 */
	public function register() {
		wp_register_style(
			'pag-widget',
			PAG_ASSETS_URL . 'css/widget.css',
			[],
			PAG_VERSION
		);

		wp_register_style(
			'pag-modal',
			PAG_ASSETS_URL . 'css/modal.css',
			[ 'pag-widget' ],
			PAG_VERSION
		);

		wp_register_script(
			'pag-widget',
			PAG_ASSETS_URL . 'js/widget.js',
			[ 'jquery' ],
			PAG_VERSION,
			true
		);
		wp_localize_script( 'pag-widget', 'PAG_DATA', $this->bootstrap_data() );

		wp_register_script(
			'pag-modal',
			PAG_ASSETS_URL . 'js/modal.js',
			[ 'pag-widget', 'wc-add-to-cart-variation' ],
			PAG_VERSION,
			true
		);

		wp_register_script(
			'pag-buy-now',
			PAG_ASSETS_URL . 'js/buy-now.js',
			[ 'pag-widget' ],
			PAG_VERSION,
			true
		);

		wp_register_script(
			'pag-wishlist',
			PAG_ASSETS_URL . 'js/wishlist.js',
			[ 'pag-widget' ],
			PAG_VERSION,
			true
		);
	}

	/**
	 * Enqueue stylesheet inside the Elementor editor preview.
	 */
	public function enqueue_editor() {
		$this->register();
		wp_enqueue_style( 'pag-widget' );
	}

	/**
	 * Bootstrap data exposed to the frontend script. Includes nonce, REST URL,
	 * cart URL, and feature flags.
	 *
	 * @return array
	 */
	private function bootstrap_data() {
		return [
			'rest_url'   => esc_url_raw( rest_url( PAG_REST_NAMESPACE . '/' ) ),
			'nonce'      => Security::nonce(),
			'cart_url'   => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' ),
			'checkout_url' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' ),
			'is_user'    => is_user_logged_in(),
			'i18n'       => [
				'added'        => __( 'Added', 'product-archive-grid' ),
				'add_to_cart'  => __( 'Add to cart', 'product-archive-grid' ),
				'view_cart'    => __( 'View cart', 'product-archive-grid' ),
				'error'        => __( 'Something went wrong. Please try again.', 'product-archive-grid' ),
				'rate_limited' => __( 'Slow down — too many requests.', 'product-archive-grid' ),
				'oos'          => __( 'Out of stock', 'product-archive-grid' ),
				'in_wishlist'  => __( 'In wishlist', 'product-archive-grid' ),
				'add_wishlist' => __( 'Add to wishlist', 'product-archive-grid' ),
				'no_more'      => __( 'No more products', 'product-archive-grid' ),
				'load_more'    => __( 'Load more', 'product-archive-grid' ),
				'loading'      => __( 'Loading…', 'product-archive-grid' ),
			],
		];
	}
}
