<?php
/**
 * "Sold count" indicator. Source: WooCommerce `total_sales` post meta
 * (only counts completed orders).
 *
 * v1.1.0: reads from $data so the same template works on the Algolia path.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product|null $product
 * @var array            $data
 */

defined( 'ABSPATH' ) || exit;

$sold = (int) ( $data['total_sales'] ?? 0 );
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
