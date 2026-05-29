<?php
/**
 * Product card template.
 *
 * Layout (per spec):
 *   Container 1 (1:1 image):
 *     - Featured image (full resolution by default)
 *     - Discount badge       — top-left
 *     - Quick View button    — top-right (desktop only)
 *     - Wishlist button      — bottom-right
 *     - Stock badge          — bottom-left
 *
 *   Container 2 (content):
 *     Row 1: Title (clamped to 2 lines)
 *     Row 2: Stars · divider · sold count   (left-aligned)
 *     Row 3: Price (current bold + old strikethrough subscript inline)
 *            Add to Cart button (rounded plus-cart, right-aligned)
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product $product
 * @var array       $settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $product ) || ! ( $product instanceof \WC_Product ) ) {
	return;
}

$permalink   = $product->get_permalink();
$product_id  = $product->get_id();
$is_variable = $product->is_type( 'variable' );

$wrapper_classes = [ 'pag-card' ];
if ( ! empty( $settings['image_hover_swap'] ) ) {
	$wrapper_classes[] = 'pag-card--hover-swap';
}
if ( $is_variable ) {
	$wrapper_classes[] = 'pag-card--variable';
}

// Stock state.
$stock = \PAG\Template::stock_state( $product );
?>
<article
	<?php wc_product_class( implode( ' ', $wrapper_classes ), $product ); ?>
	role="listitem"
	data-product-id="<?php echo esc_attr( $product_id ); ?>"
	data-product-type="<?php echo esc_attr( $product->get_type() ); ?>"
	data-permalink="<?php echo esc_url( $permalink ); ?>"
>
	<?php if ( ! empty( $settings['show_image'] ) ) : ?>
		<div class="pag-card__image">
			<a class="pag-card__image-link" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( $product->get_name() ); ?>">
				<?php
				$size = isset( $settings['image_size'] ) ? $settings['image_size'] : 'full';
				if ( has_post_thumbnail( $product_id ) ) {
					echo wp_kses_post(
						get_the_post_thumbnail(
							$product_id,
							$size,
							[
								'class'   => 'pag-card__image-main',
								'loading' => 'lazy',
								'alt'     => esc_attr( $product->get_name() ),
							]
						)
					);

					if ( ! empty( $settings['image_hover_swap'] ) ) {
						$gallery_ids = $product->get_gallery_image_ids();
						if ( ! empty( $gallery_ids[0] ) ) {
							echo wp_kses_post(
								wp_get_attachment_image(
									$gallery_ids[0],
									$size,
									false,
									[
										'class'   => 'pag-card__image-hover',
										'loading' => 'lazy',
										'alt'     => '',
									]
								)
							);
						}
					}
				} else {
					echo wp_kses_post( wc_placeholder_img( $size ) );
				}
				?>
			</a>

			<?php if ( ! empty( $settings['show_discount_badge'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/badge-discount', [ 'product' => $product, 'settings' => $settings ] ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $settings['show_quick_view'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/quick-view-button', [ 'product' => $product, 'settings' => $settings ] ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $settings['show_wishlist'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/wishlist-button', [ 'product' => $product, 'settings' => $settings ] ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $settings['show_stock_badge'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/badge-stock', [ 'product' => $product, 'settings' => $settings, 'stock' => $stock ] ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="pag-card__body">
		<?php if ( ! empty( $settings['show_title'] ) ) : ?>
			<h3 class="pag-card__title">
				<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
			</h3>
		<?php endif; ?>

		<?php
		$show_rating = ! empty( $settings['show_rating'] );
		$show_sold   = ! empty( $settings['show_sold'] );
		if ( $show_rating || $show_sold ) :
			?>
			<div class="pag-card__meta">
				<?php
				if ( $show_rating ) {
					\PAG\Template::load_part( 'parts/rating', [ 'product' => $product, 'settings' => $settings ] );
				}
				if ( $show_sold ) {
					\PAG\Template::load_part( 'parts/sold', [ 'product' => $product ] );
				}
				?>
			</div>
		<?php endif; ?>

		<?php
		$show_price = ! empty( $settings['show_price'] );
		$show_atc   = ! empty( $settings['show_add_to_cart'] );
		if ( $show_price || $show_atc ) :
			?>
			<div class="pag-card__row-price">
				<?php
				if ( $show_price ) {
					\PAG\Template::load_part( 'parts/price', [ 'product' => $product, 'settings' => $settings ] );
				} else {
					echo '<span class="pag-price-spacer"></span>';
				}
				if ( $show_atc ) {
					\PAG\Template::load_part( 'parts/add-to-cart', [ 'product' => $product, 'settings' => $settings ] );
				}
				?>
			</div>
		<?php endif; ?>

		<?php
		if ( ! empty( $settings['show_vendor'] ) ) :
			$vendor_id = (int) $product->get_post_data()->post_author;
			$info      = \PAG\Compat_Dokan::get_vendor_info( $vendor_id );
			if ( $info && ! empty( $info['name'] ) ) :
				?>
				<div class="pag-card__vendor">
					<?php esc_html_e( 'Sold by', 'product-archive-grid' ); ?>
					<?php if ( ! empty( $info['url'] ) ) : ?>
						<a href="<?php echo esc_url( $info['url'] ); ?>"><?php echo esc_html( $info['name'] ); ?></a>
					<?php else : ?>
						<span><?php echo esc_html( $info['name'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</article>
