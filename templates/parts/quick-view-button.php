<?php
/**
 * Quick-View overlay button. Wired up in PR-B; in PR-A it falls back to
 * linking to the product page so it remains functional.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 * @var array       $settings
 */

defined( 'ABSPATH' ) || exit;

$icon          = isset( $settings['icon_html']['quick_view'] ) ? $settings['icon_html']['quick_view'] : \PAG\Template::icon( 'eye' );
$has_quick_view = class_exists( '\PAG\Quick_View' );
$tag           = $has_quick_view ? 'button' : 'a';
$attr          = $has_quick_view
	? ' type="button"'
	: ' href="' . esc_url( $product->get_permalink() ) . '"';
?>
<<?php echo esc_attr( $tag ); ?>
	<?php echo $attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	class="pag-card__icon-btn pag-card__quick-view"
	data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
	aria-label="<?php
		echo esc_attr(
			sprintf(
				/* translators: %s product name */
				__( 'Quick view of %s', 'product-archive-grid' ),
				$product->get_name()
			)
		);
	?>"
>
	<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kses'd ?>
</<?php echo esc_attr( $tag ); ?>>
