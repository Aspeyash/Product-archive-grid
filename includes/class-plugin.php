<?php
/**
 * Singleton bootstrapper. Loads sub-systems and wires hooks.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level plugin orchestrator.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Assets */
	public $assets;

	/** @var Rate_Limiter */
	public $rate_limiter;

	/** @var REST_API */
	public $rest_api;

	/** @var Wishlist|null */
	public $wishlist = null;

	/** @var Buy_Now|null */
	public $buy_now = null;

	/** @var Quick_View|null */
	public $quick_view = null;

	/**
	 * Get singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		$this->load_files();
		$this->init_subsystems();
		$this->register_hooks();
	}

	/**
	 * Require all dependencies. Order matters (security must load before REST).
	 */
	private function load_files() {
		$files = [
			'class-security.php',
			'class-rate-limiter.php',
			'class-rest-api.php',
			'class-query.php',
			'class-template.php',
			'class-assets.php',
			'class-compat-astra.php',
			'class-compat-dokan.php',
		];

		// Optional sub-systems shipped in later PRs (Quick View + Buy Now / Wishlist).
		$optional = [
			'class-quick-view.php',
			'class-buy-now.php',
			'class-wishlist.php',
		];

		foreach ( $files as $file ) {
			require_once PAG_INCLUDES_DIR . $file;
		}

		foreach ( $optional as $file ) {
			$path = PAG_INCLUDES_DIR . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Instantiate sub-systems exactly once.
	 */
	private function init_subsystems() {
		$this->rate_limiter = new Rate_Limiter();
		$this->assets       = new Assets();
		$this->rest_api     = new REST_API( $this->rate_limiter );

		if ( class_exists( __NAMESPACE__ . '\\Quick_View' ) ) {
			$this->quick_view = new Quick_View( $this->rest_api, $this->rate_limiter );
		}
		if ( class_exists( __NAMESPACE__ . '\\Buy_Now' ) ) {
			$this->buy_now = new Buy_Now( $this->rest_api, $this->rate_limiter );
		}
		if ( class_exists( __NAMESPACE__ . '\\Wishlist' ) ) {
			$this->wishlist = new Wishlist( $this->rest_api, $this->rate_limiter );
		}

		// Compat shims (idempotent).
		new Compat_Astra();
		new Compat_Dokan();
	}

	/**
	 * Register Elementor hooks.
	 */
	private function register_hooks() {
		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
	}

	/**
	 * Register a dedicated category in the Elementor panel.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Manager.
	 */
	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'pag-woocommerce',
			[
				'title' => __( 'Product Archive Grid', 'product-archive-grid' ),
				'icon'  => 'eicon-products',
			]
		);
	}

	/**
	 * Register widgets with Elementor.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Manager.
	 */
	public function register_widgets( $widgets_manager ) {
		require_once PAG_INCLUDES_DIR . 'widgets/class-product-grid-widget.php';
		$widgets_manager->register( new Widgets\Product_Grid_Widget() );
	}
}
