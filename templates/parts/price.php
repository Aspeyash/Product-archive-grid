<?php
/**
 * Price block.
 *
 * Variable products: lowest sale price + that variation's regular price
 * strikethrough inline. Simple sale: current bold + old strikethrough subscript.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 * @var array       $settings
 */

defined( 'ABSPATH' ) || exit;

$show_old = ! empty( $settings['show_old_price'] );
$html     = \PAG\Template::price_html( $product );

if ( ! $show_old ) {
	// Strip the <del> subscript when the user has chosen to hide old prices.
	$html = preg_replace( '#<del class="pag-price__old".*?</del>#is', '', $html );
}
?>
<div class="pag-price">
	<?php echo wp_kses_post( $html ); ?>
</div>
