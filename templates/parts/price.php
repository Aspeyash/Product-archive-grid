<?php
/**
 * Price block.
 *
 * Variable products: lowest sale price + that variation's regular price
 * strikethrough inline. Simple sale: current bold + old strikethrough subscript.
 *
 * v1.1.0: when the WC_Product isn't available (Algolia simple-product path)
 * we fall back to either the indexed price_html or build it from the
 * regular/sale prices stored in $data.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product|null $product
 * @var array            $data
 * @var array            $settings
 */

defined( 'ABSPATH' ) || exit;

$show_old = ! empty( $settings['show_old_price'] );

if ( $product instanceof \WC_Product ) {
	$html = \PAG\Template::price_html( $product );
} else {
	$regular = (float) ( $data['regular_price'] ?? 0 );
	$sale    = (float) ( $data['sale_price'] ?? 0 );
	$on_sale = ! empty( $data['on_sale'] ) && $sale > 0 && $sale < $regular;
	if ( $on_sale ) {
		$html  = '<span class="pag-price__current">' . wp_kses_post( wc_price( $sale ) ) . '</span>';
		$html .= ' <del class="pag-price__old"><sub>' . wp_kses_post( wc_price( $regular ) ) . '</sub></del>';
	} elseif ( ! empty( $data['price_html'] ) ) {
		$html = '<span class="pag-price__current">' . wp_kses_post( (string) $data['price_html'] ) . '</span>';
	} elseif ( $regular > 0 ) {
		$html = '<span class="pag-price__current">' . wp_kses_post( wc_price( $regular ) ) . '</span>';
	} else {
		$html = '<span class="pag-price__current"></span>';
	}
}

if ( ! $show_old ) {
	// Strip the <del> subscript when the user has chosen to hide old prices.
	$html = preg_replace( '#<del class="pag-price__old".*?</del>#is', '', $html );
}
?>
<div class="pag-price">
	<?php echo wp_kses_post( $html ); ?>
</div>
