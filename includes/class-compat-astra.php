<?php
/**
 * Astra theme compatibility shim. Astra wraps the WC product loop in its own
 * markup and adds many `astra_woo_*` hooks. Our widget renders independently of
 * `woocommerce_template_loop_*` hooks so it is not affected by Astra's loop
 * customisations — but we still respect Astra's site container width and let
 * its CSS variables flow through where applicable.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Adds CSS hooks that consume Astra's published variables when present.
 */
class Compat_Astra {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! defined( 'ASTRA_THEME_VERSION' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ $this, 'inline_astra_bridge' ], 30 );
	}

	/**
	 * Inject a small inline stylesheet that maps Astra theme tokens into the
	 * `--pag-*` design tokens our widget uses, so the grid feels native.
	 */
	public function inline_astra_bridge() {
		$css = <<<CSS
.pag-grid-wrapper {
	--pag-card-bg: var(--ast-global-color-5, #fff);
	--pag-text: var(--ast-global-color-3, #1f2937);
	--pag-muted: var(--ast-global-color-4, #6b7280);
	--pag-accent: var(--ast-global-color-0, #111);
}
CSS;
		// Attach to our stylesheet (registered earlier).
		wp_add_inline_style( 'pag-widget', $css );
	}
}
