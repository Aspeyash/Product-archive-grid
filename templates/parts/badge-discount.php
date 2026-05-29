<?php
/**
 * Discount badge.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 * @var array       $settings
 */

defined( 'ABSPATH' ) || exit;

$info = \PAG\Template::discount_info( $product );
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
