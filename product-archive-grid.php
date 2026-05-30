<?php
/**
 * Plugin Name:       Product Archive Grid for Elementor
 * Plugin URI:        https://github.com/Aspeyash/Product-archive-grid
 * Description:       A premium Elementor widget for WooCommerce product archives. Renders a fully-customisable responsive grid with discount/stock badges, quick view, custom wishlist, AJAX add-to-cart, Buy Now flow that preserves the existing cart, and Astra/Dokan compatibility.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ZYMARG
 * Author URI:        https://zymarg.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-archive-grid
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 * Elementor tested up to: 3.23
 *
 * @package ProductArchiveGrid
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants — single source of truth for paths, URLs, versions.
// ---------------------------------------------------------------------------
define( 'PAG_VERSION', '1.1.1' );
define( 'PAG_PLUGIN_FILE', __FILE__ );
define( 'PAG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PAG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PAG_INCLUDES_DIR', PAG_PLUGIN_DIR . 'includes/' );
define( 'PAG_TEMPLATES_DIR', PAG_PLUGIN_DIR . 'templates/' );
define( 'PAG_ASSETS_URL', PAG_PLUGIN_URL . 'assets/' );
define( 'PAG_REST_NAMESPACE', 'pag/v1' );
define( 'PAG_TEXT_DOMAIN', 'product-archive-grid' );
define( 'PAG_MIN_PHP', '7.4' );
define( 'PAG_MIN_WP', '6.0' );
define( 'PAG_MIN_ELEMENTOR', '3.5.0' );
define( 'PAG_MIN_WC', '7.0' );

// ---------------------------------------------------------------------------
// i18n.
// ---------------------------------------------------------------------------
add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			PAG_TEXT_DOMAIN,
			false,
			dirname( PAG_PLUGIN_BASENAME ) . '/languages'
		);
	}
);

// ---------------------------------------------------------------------------
// Admin notice helper.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'pag_admin_notice' ) ) {
	/**
	 * Print a dismissible admin notice.
	 *
	 * @param string $message HTML-safe message.
	 * @param string $level   Notice level (error|warning|success|info).
	 */
	function pag_admin_notice( $message, $level = 'warning' ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $level ),
			wp_kses_post( $message )
		);
	}
}

// ---------------------------------------------------------------------------
// Dependency check. Never run plugin code if dependencies aren't satisfied.
// ---------------------------------------------------------------------------
/**
 * Verify all dependencies are present.
 *
 * @return bool
 */
function pag_check_dependencies() {
	if ( version_compare( PHP_VERSION, PAG_MIN_PHP, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				pag_admin_notice(
					sprintf(
						/* translators: 1: required, 2: current */
						esc_html__( 'Product Archive Grid requires PHP %1$s+. You are running %2$s.', 'product-archive-grid' ),
						esc_html( PAG_MIN_PHP ),
						esc_html( PHP_VERSION )
					),
					'error'
				);
			}
		);
		return false;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				pag_admin_notice(
					esc_html__( 'Product Archive Grid requires WooCommerce to be installed and active.', 'product-archive-grid' ),
					'error'
				);
			}
		);
		return false;
	}

	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action(
			'admin_notices',
			static function () {
				pag_admin_notice(
					esc_html__( 'Product Archive Grid requires Elementor to be installed and active.', 'product-archive-grid' ),
					'error'
				);
			}
		);
		return false;
	}

	if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, PAG_MIN_ELEMENTOR, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				pag_admin_notice(
					sprintf(
						/* translators: %s: required version */
						esc_html__( 'Product Archive Grid requires Elementor %s or higher.', 'product-archive-grid' ),
						esc_html( PAG_MIN_ELEMENTOR )
					),
					'error'
				);
			}
		);
		return false;
	}

	return true;
}

// ---------------------------------------------------------------------------
// HPOS (custom order tables) compat — declared early per WC requirements.
// ---------------------------------------------------------------------------
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PAG_PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				PAG_PLUGIN_FILE,
				true
			);
		}
	}
);

// ---------------------------------------------------------------------------
// Bootstrap once Elementor + WC are loaded.
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function () {
		if ( ! pag_check_dependencies() ) {
			return;
		}
		require_once PAG_INCLUDES_DIR . 'class-plugin.php';
		\PAG\Plugin::instance();
	},
	20
);

// ---------------------------------------------------------------------------
// Activation hook — set defaults, schedule cleanup.
// ---------------------------------------------------------------------------
register_activation_hook(
	__FILE__,
	static function () {
		// Reserved for future schema; nothing to create yet.
		if ( ! get_option( 'pag_installed_at' ) ) {
			update_option( 'pag_installed_at', time(), false );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		// Clear scheduled events if any are added later.
		wp_clear_scheduled_hook( 'pag_cleanup_event' );
	}
);
