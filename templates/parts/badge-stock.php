<?php
/**
 * Stock badge.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 * @var array       $stock { state, label }
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $stock ) || empty( $stock['state'] ) ) {
	return;
}
?>
<span class="pag-badge pag-badge--stock is-<?php echo esc_attr( $stock['state'] ); ?>" aria-label="<?php echo esc_attr( $stock['label'] ); ?>">
	<?php echo \PAG\Template::icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — already kses'd ?>
	<span class="pag-badge__text"><?php echo esc_html( $stock['label'] ); ?></span>
</span>
