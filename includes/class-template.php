<?php
/**
 * Template renderer. All HTML output for cards/parts/empty/heading lives here.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Static rendering helpers.
 */
final class Template {

	/**
	 * Render a single product card.
	 *
	 * @param int   $product_id Product post ID.
	 * @param array $settings   Sanitised settings.
	 */
	public static function render_card( $product_id, array $settings ) {
		$product = Security::get_validated_product( $product_id );
		if ( ! $product || ! $product->is_visible() ) {
			return;
		}

		$ctx = [
			'product'  => $product,
			'settings' => $settings,
		];

		self::load_part( 'card', $ctx );
	}

	/**
	 * Render the heading section above the grid.
	 *
	 * @param array $settings Settings.
	 */
	public static function render_heading( array $settings ) {
		if ( empty( $settings['show_heading'] ) ) {
			return;
		}
		self::load_part( 'heading', [ 'settings' => $settings ] );
	}

	/**
	 * Render the empty-state block.
	 */
	public static function render_empty() {
		self::load_part( 'empty' );
	}

	/**
	 * Load a template file with extracted variables.
	 *
	 * @param string $name Template name (matches templates/<name>.php).
	 * @param array  $vars Variables to extract.
	 */
	public static function load_part( $name, array $vars = [] ) {
		$file = PAG_TEMPLATES_DIR . sanitize_file_name( $name ) . '.php';
		if ( ! file_exists( $file ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DontExtract
		extract( $vars, EXTR_SKIP );
		include $file;
	}

	/**
	 * Compute the discount percent / amount for a product.
	 *
	 * Variable products: max discount across variations.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{percent:int, amount:float, regular:float, sale:float} or empty array.
	 */
	public static function discount_info( $product ) {
		if ( ! $product->is_on_sale() ) {
			return [];
		}

		$regular = 0.0;
		$sale    = 0.0;

		if ( $product->is_type( 'variable' ) ) {
			$max_pct = 0;
			$best    = [];
			foreach ( $product->get_visible_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || ! $v->is_on_sale() ) {
					continue;
				}
				$r = (float) $v->get_regular_price();
				$s = (float) $v->get_sale_price();
				if ( $r <= 0 || $s < 0 || $s >= $r ) {
					continue;
				}
				$pct = (int) round( ( ( $r - $s ) / $r ) * 100 );
				if ( $pct > $max_pct ) {
					$max_pct = $pct;
					$best    = [
						'percent' => $pct,
						'amount'  => $r - $s,
						'regular' => $r,
						'sale'    => $s,
					];
				}
			}
			return $best;
		}

		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
		if ( $regular <= 0 || $sale < 0 || $sale >= $regular ) {
			return [];
		}
		return [
			'percent' => (int) round( ( ( $regular - $sale ) / $regular ) * 100 ),
			'amount'  => $regular - $sale,
			'regular' => $regular,
			'sale'    => $sale,
		];
	}

	/**
	 * Build the price HTML the way the brief specifies:
	 *   - Variable products: show the LOWEST sale price + that variation's
	 *     regular price strikethrough inline next to it.
	 *   - Simple/sale: current bold + old strikethrough subscript.
	 *
	 * @param \WC_Product $product Product.
	 * @return string HTML.
	 */
	public static function price_html( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$lowest_regular = 0.0;
			$lowest_sale    = 0.0;
			$found          = false;
			foreach ( $product->get_visible_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) {
					continue;
				}
				$current = $v->is_on_sale() ? (float) $v->get_sale_price() : (float) $v->get_price();
				if ( $current <= 0 ) {
					continue;
				}
				if ( ! $found || $current < $lowest_sale ) {
					$lowest_sale    = $current;
					$lowest_regular = (float) $v->get_regular_price();
					$found          = true;
				}
			}
			if ( ! $found ) {
				return $product->get_price_html();
			}
			$out = '<span class="pag-price__current">' . wp_kses_post( wc_price( $lowest_sale ) ) . '</span>';
			if ( $lowest_regular > $lowest_sale ) {
				$out .= ' <del class="pag-price__old"><sub>' . wp_kses_post( wc_price( $lowest_regular ) ) . '</sub></del>';
			}
			return $out;
		}

		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
		if ( $product->is_on_sale() && $regular > 0 && $sale > 0 && $sale < $regular ) {
			return '<span class="pag-price__current">' . wp_kses_post( wc_price( $sale ) ) . '</span>'
				. ' <del class="pag-price__old"><sub>' . wp_kses_post( wc_price( $regular ) ) . '</sub></del>';
		}

		return '<span class="pag-price__current">' . wp_kses_post( $product->get_price_html() ) . '</span>';
	}

	/**
	 * Stock state for the badge: 'instock' | 'outofstock' | 'onbackorder' | 'lowstock'.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{state:string,label:string}
	 */
	public static function stock_state( $product ) {
		$state = $product->get_stock_status();
		$qty   = $product->get_stock_quantity();
		$low   = (int) apply_filters( 'pag_low_stock_threshold', 5, $product );

		if ( 'outofstock' === $state ) {
			return [
				'state' => 'outofstock',
				'label' => __( 'Out of stock', 'product-archive-grid' ),
			];
		}
		if ( 'onbackorder' === $state ) {
			return [
				'state' => 'onbackorder',
				'label' => __( 'On backorder', 'product-archive-grid' ),
			];
		}
		if ( null !== $qty && $qty > 0 && $qty <= $low ) {
			return [
				'state' => 'lowstock',
				'label' => sprintf(
					/* translators: %d remaining stock */
					__( 'Only %d left', 'product-archive-grid' ),
					$qty
				),
			];
		}
		return [
			'state' => 'instock',
			'label' => __( 'In stock', 'product-archive-grid' ),
		];
	}

	/**
	 * Inline SVG icon registry. Returns escape-safe SVG markup.
	 *
	 * @param string $name   Icon key.
	 * @param string $extras Extra HTML attributes passed via array merge in render.
	 * @return string
	 */
	public static function icon( $name ) {
		$svg = '';
		switch ( $name ) {
			case 'cart-plus':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 4h2l2.4 11.4a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 2-1.6L22 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="20" r="1.6" fill="currentColor"/><circle cx="18" cy="20" r="1.6" fill="currentColor"/><path d="M12 9v6M9 12h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
				break;
			case 'eye':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>';
				break;
			case 'heart':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M12 21s-7-4.5-9.5-9A5 5 0 0 1 12 6a5 5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
				break;
			case 'heart-filled':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 21s-7-4.5-9.5-9A5 5 0 0 1 12 6a5 5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9Z"/></svg>';
				break;
			case 'star':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.1 7 14.2 2 9.3l6.9-1L12 2Z"/></svg>';
				break;
			case 'star-empty':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.1 7 14.2 2 9.3l6.9-1L12 2Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
				break;
			case 'check':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="m5 12 5 5L20 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
				break;
			case 'x':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M6 6l12 12M6 18 18 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
				break;
			case 'box':
				$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 7h18l-2 13H5L3 7Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 7V5a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="2"/></svg>';
				break;
		}
		return Security::kses_svg( $svg );
	}
}
