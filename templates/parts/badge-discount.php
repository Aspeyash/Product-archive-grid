<?php
/**
 * Discount badge.
 *
 * v1.1.0: when the WC_Product isn't available (Algolia simple-product path)
 * the discount info is computed from $data's regular/sale prices.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product|null $product
 * @var array            $data
 * @var array            $settings
 */

defined( 'ABSPATH' ) || exit;

if ( $product instanceof \WC_Product ) {
	$info = \PAG\Template::discount_info( $product );
} else {
	$info = [];
	if ( ! empty( $data['on_sale'] ) ) {
		$regular = (float) ( $data['regular_price'] ?? 0 );
		$sale    = (float) ( $data['sale_price'] ?? 0 );
		if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
			$info = [
				'percent' => (int) round( ( ( $regular - $sale ) / $regular ) * 100 ),
				'amount'  => $regular - $sale,
				'regular' => $regular,
				'sale'    => $sale,
			];
		}
	}
}

if ( empty( $info ) ) {
	return;
}

$format = isset( $settings['discount_format'] ) ? $settings['discount_format'] : 'percent';

ob_start();
switch ( $format ) {
	case 'amount':
		printf(
			/* translators: %s amount saved */
			esc_html__( 'Save %s', 'product-archive-grid' ),
			wp_kses_post( wc_price( $info['amount'] ) )
		);
		break;
	case 'both':
		printf(
			'-%d%% <span class="pag-badge__amount">(%s)</span>',
			(int) $info['percent'],
			wp_kses_post(
				sprintf(
					/* translators: %s amount saved */
					esc_html__( 'Save %s', 'product-archive-grid' ),
					wp_strip_all_tags( wc_price( $info['amount'] ) )
				)
			)
		);
		break;
	case 'percent':
	default:
		printf( '-%d%%', (int) $info['percent'] );
		break;
}
$content = ob_get_clean();
?>
<span class="pag-badge pag-badge--discount" aria-label="<?php echo esc_attr__( 'Discount', 'product-archive-grid' ); ?>">
	<?php echo wp_kses_post( $content ); ?>
</span>
