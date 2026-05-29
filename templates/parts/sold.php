<?php
/**
 * "Sold count" indicator. Source: WooCommerce `total_sales` post meta
 * (only counts completed orders).
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 */

defined( 'ABSPATH' ) || exit;

$sold = (int) get_post_meta( $product->get_id(), 'total_sales', true );
if ( $sold <= 0 ) {
	return;
}

// Format thresholds: 1.2k / 12k / 1.2M.
$display = $sold;
if ( $sold >= 1000000 ) {
	$display = number_format_i18n( $sold / 1000000, 1 ) . __( 'M', 'product-archive-grid' );
} elseif ( $sold >= 1000 ) {
	$display = number_format_i18n( $sold / 1000, 1 ) . __( 'k', 'product-archive-grid' );
} else {
	$display = number_format_i18n( $sold );
}
?>
<span class="pag-rating__divider" aria-hidden="true">·</span>
<span class="pag-sold">
	<?php
	printf(
		/* translators: %s formatted sold count */
		esc_html__( '%s sold', 'product-archive-grid' ),
		esc_html( (string) $display )
	);
	?>
</span>
