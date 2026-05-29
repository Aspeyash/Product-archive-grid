<?php
/**
 * Add-to-cart button (rounded icon-only). Behaviour:
 *   - simple, in-stock, purchasable → AJAX add (handled in widget.js)
 *   - variable                       → redirect to product page
 *   - external                       → external URL
 *   - grouped / out-of-stock         → link to product page
 *
 * v1.1.0: now reads from $data so the same template renders identically on
 * the Algolia data path. $product is null when an Algolia simple product is
 * being rendered — for those we always emit the AJAX path.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product|null $product
 * @var array            $data
 * @var array            $settings
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $data ) || empty( $data['id'] ) ) {
	return;
}

$icon = isset( $settings['icon_html']['add_to_cart'] ) ? $settings['icon_html']['add_to_cart'] : \PAG\Template::icon( 'cart-plus' );

$type = (string) ( $data['product_type'] ?? 'simple' );

if ( $product instanceof \WC_Product ) {
	$ajax     = ( 'simple' === $type ) && $product->is_purchasable() && $product->is_in_stock();
	$disabled = ! $product->is_purchasable() || ! $product->is_in_stock();
	$href     = $ajax ? '#' : $product->add_to_cart_url();
	$label    = $product->add_to_cart_text();
} else {
	// Algolia simple-product path — Woo isn't asked. Stock + purchasability
	// are best-effort from the indexed data.
	$ajax     = ( 'simple' === $type ) && ! empty( $data['in_stock'] );
	$disabled = empty( $data['in_stock'] );
	$href     = $ajax ? '#' : (string) $data['permalink'];
	$label    = ( 'simple' === $type )
		? __( 'Add to cart', 'product-archive-grid' )
		: __( 'Read more', 'product-archive-grid' );
}

$classes = [ 'pag-card__atc' ];
if ( $ajax ) {
	$classes[] = 'is-ajax';
}
if ( $disabled && ! $ajax ) {
	$classes[] = 'is-disabled';
}
if ( 'variable' === $type ) {
	$classes[] = 'is-variable';
}
?>
<a
	href="<?php echo esc_url( $href ); ?>"
	class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	data-product-id="<?php echo esc_attr( (int) $data['id'] ); ?>"
	data-product-type="<?php echo esc_attr( $type ); ?>"
	data-product-url="<?php echo esc_url( (string) $data['permalink'] ); ?>"
	aria-label="<?php
		echo esc_attr(
			sprintf(
				/* translators: %s product name */
				__( '%s — add to cart', 'product-archive-grid' ),
				(string) $data['name']
			)
		);
	?>"
	title="<?php echo esc_attr( $label ); ?>"
	rel="nofollow"
>
	<span class="pag-card__atc-icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — already kses'd in widget ?></span>
	<span class="pag-sr-only"><?php echo esc_html( $label ); ?></span>
</a>
