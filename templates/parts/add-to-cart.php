<?php
/**
 * Add-to-cart button (rounded icon-only). Behaviour:
 *   - simple, in-stock, purchasable → AJAX add (handled in widget.js)
 *   - variable                       → redirect to product page
 *   - external                       → external URL
 *   - grouped / out-of-stock         → link to product page
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 * @var array       $settings
 */

defined( 'ABSPATH' ) || exit;

$icon = isset( $settings['icon_html']['add_to_cart'] ) ? $settings['icon_html']['add_to_cart'] : \PAG\Template::icon( 'cart-plus' );

$type     = $product->get_type();
$ajax     = ( 'simple' === $type ) && $product->is_purchasable() && $product->is_in_stock();
$disabled = ! $product->is_purchasable() || ! $product->is_in_stock();

$href  = $ajax ? '#' : $product->add_to_cart_url();
$label = $product->add_to_cart_text();

$classes = [ 'pag-card__atc' ];
if ( $ajax ) {
	$classes[] = 'is-ajax';
}
if ( $disabled && ! $ajax ) {
	$classes[] = 'is-disabled';
}
if ( $product->is_type( 'variable' ) ) {
	$classes[] = 'is-variable';
}
?>
<a
	href="<?php echo esc_url( $href ); ?>"
	class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
	data-product-type="<?php echo esc_attr( $type ); ?>"
	data-product-url="<?php echo esc_url( $product->get_permalink() ); ?>"
	aria-label="<?php
		echo esc_attr(
			sprintf(
				/* translators: %s product name */
				__( '%s — add to cart', 'product-archive-grid' ),
				$product->get_name()
			)
		);
	?>"
	title="<?php echo esc_attr( $label ); ?>"
	rel="nofollow"
>
	<span class="pag-card__atc-icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — already kses'd in widget ?></span>
	<span class="pag-sr-only"><?php echo esc_html( $label ); ?></span>
</a>
