<?php
/**
 * Wishlist toggle button. Functional in PR-C; in PR-A it renders a stub that
 * persists locally only (no server round-trip), so the markup stays stable
 * across PRs.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 * @var array       $settings
 */

defined( 'ABSPATH' ) || exit;

$icon        = isset( $settings['icon_html']['wishlist'] ) ? $settings['icon_html']['wishlist'] : \PAG\Template::icon( 'heart' );
$icon_active = isset( $settings['icon_html']['wishlist_active'] ) ? $settings['icon_html']['wishlist_active'] : \PAG\Template::icon( 'heart-filled' );

$is_active   = false;
if ( class_exists( '\PAG\Wishlist' ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
	$is_active = (bool) \PAG\Wishlist::contains( get_current_user_id(), $product->get_id() );
}
?>
<button
	type="button"
	class="pag-card__icon-btn pag-card__wishlist <?php echo $is_active ? 'is-active' : ''; ?>"
	data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
	aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
	aria-label="<?php
		echo esc_attr(
			$is_active
				? __( 'Remove from wishlist', 'product-archive-grid' )
				: __( 'Add to wishlist', 'product-archive-grid' )
		);
	?>"
>
	<span class="pag-card__icon-default" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kses'd ?></span>
	<span class="pag-card__icon-active" aria-hidden="true"><?php echo $icon_active; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kses'd ?></span>
</button>
