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
 * v1.1.0: now reads from $data (normalised by pag_card_data()) so the same
 * markup works for WP_Query (real WC_Product) and Algolia data paths.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product|null $product Real WC_Product on the WP_Query path; may be
 *                                 null on the Algolia simple-product path.
 * @var array            $data    Normalised card data (always present).
 * @var array            $settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $data ) || ! is_array( $data ) || empty( $data['id'] ) ) {
	return;
}

$is_algolia  = ! empty( $data['is_algolia'] );
$permalink   = (string) $data['permalink'];
$product_id  = (int) $data['id'];
$is_variable = ( 'variable' === ( $data['product_type'] ?? 'simple' ) );

$wrapper_classes = [ 'pag-card' ];
if ( ! empty( $settings['image_hover_swap'] ) ) {
	$wrapper_classes[] = 'pag-card--hover-swap';
}
if ( $is_variable ) {
	$wrapper_classes[] = 'pag-card--variable';
}

// Stock state. WC_Product path uses the existing helper for full fidelity
// (low-stock threshold, backorder). Algolia path computes a minimal version
// from $data without a DB round-trip.
if ( $product instanceof \WC_Product ) {
	$stock = \PAG\Template::stock_state( $product );
} else {
	$qty       = $data['stock_qty'];
	$low       = (int) apply_filters( 'pag_low_stock_threshold', 5, null );
	$in_stock  = (bool) $data['in_stock'];
	$stock     = $in_stock
		? [ 'state' => 'instock', 'label' => __( 'In stock', 'product-archive-grid' ) ]
		: [ 'state' => 'outofstock', 'label' => __( 'Out of stock', 'product-archive-grid' ) ];
	if ( $in_stock && null !== $qty && $qty > 0 && $qty <= $low ) {
		$stock = [
			'state' => 'lowstock',
			/* translators: %d remaining stock */
			'label' => sprintf( __( 'Only %d left', 'product-archive-grid' ), (int) $qty ),
		];
	}
}
?>
<article
	<?php
	if ( $product instanceof \WC_Product ) {
		wc_product_class( implode( ' ', $wrapper_classes ), $product );
	} else {
		echo 'class="' . esc_attr( implode( ' ', $wrapper_classes ) . ' product type-product post-' . $product_id ) . '"';
	}
	?>
	role="listitem"
	data-product-id="<?php echo esc_attr( $product_id ); ?>"
	data-product-type="<?php echo esc_attr( (string) $data['product_type'] ); ?>"
	data-permalink="<?php echo esc_url( $permalink ); ?>"
>
	<?php if ( ! empty( $settings['show_image'] ) ) : ?>
		<div class="pag-card__image">
			<a class="pag-card__image-link" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( $data['name'] ); ?>">
				<?php
				$size = isset( $settings['image_size'] ) ? $settings['image_size'] : 'full';
				if ( $product instanceof \WC_Product ) {
					if ( has_post_thumbnail( $product_id ) ) {
						echo wp_kses_post(
							get_the_post_thumbnail(
								$product_id,
								$size,
								[
									'class'   => 'pag-card__image-main',
									'loading' => 'lazy',
									'alt'     => esc_attr( $data['name'] ),
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
				} else {
					// Algolia simple-product path — render straight <img>.
					$src = (string) $data['thumbnail'];
					if ( '' === $src && function_exists( 'wc_placeholder_img_src' ) ) {
						$src = (string) wc_placeholder_img_src( $size );
					}
					if ( '' !== $src ) {
						printf(
							'<img class="pag-card__image-main" src="%s" alt="%s" loading="lazy" />',
							esc_url( $src ),
							esc_attr( $data['name'] )
						);
					}
				}
				?>
			</a>

			<?php if ( ! empty( $settings['show_discount_badge'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/badge-discount', [ 'product' => $product, 'data' => $data, 'settings' => $settings ] ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $settings['show_quick_view'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/quick-view-button', [ 'product' => $product, 'data' => $data, 'settings' => $settings ] ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $settings['show_wishlist'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/wishlist-button', [ 'product' => $product, 'data' => $data, 'settings' => $settings ] ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $settings['show_stock_badge'] ) ) : ?>
				<?php \PAG\Template::load_part( 'parts/badge-stock', [ 'product' => $product, 'data' => $data, 'settings' => $settings, 'stock' => $stock ] ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="pag-card__body">
		<?php if ( ! empty( $settings['show_title'] ) ) : ?>
			<h3 class="pag-card__title">
				<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $data['name'] ); ?></a>
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
					\PAG\Template::load_part( 'parts/rating', [ 'product' => $product, 'data' => $data, 'settings' => $settings ] );
				}
				if ( $show_sold ) {
					\PAG\Template::load_part( 'parts/sold', [ 'product' => $product, 'data' => $data ] );
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
					\PAG\Template::load_part( 'parts/price', [ 'product' => $product, 'data' => $data, 'settings' => $settings ] );
				} else {
					echo '<span class="pag-price-spacer"></span>';
				}
				if ( $show_atc ) {
					\PAG\Template::load_part( 'parts/add-to-cart', [ 'product' => $product, 'data' => $data, 'settings' => $settings ] );
				}
				?>
			</div>
		<?php endif; ?>

		<?php
		if ( ! empty( $settings['show_vendor'] ) ) :
			$vendor_name = '';
			$vendor_url  = '';
			if ( $is_algolia && ! empty( $data['vendor_name'] ) ) {
				$vendor_name = (string) $data['vendor_name'];
				$vendor_url  = (string) $data['vendor_url'];
			} else {
				$vendor_id = ! empty( $data['vendor_id'] )
					? (int) $data['vendor_id']
					: ( $product instanceof \WC_Product ? (int) get_post_field( 'post_author', $product->get_id() ) : 0 );
				$info      = \PAG\Compat_Dokan::get_vendor_info( $vendor_id );
				if ( $info ) {
					$vendor_name = (string) ( $info['name'] ?? '' );
					$vendor_url  = (string) ( $info['url'] ?? '' );
				}
			}
			if ( '' !== $vendor_name ) :
				?>
				<div class="pag-card__vendor">
					<?php esc_html_e( 'Sold by', 'product-archive-grid' ); ?>
					<?php if ( '' !== $vendor_url ) : ?>
						<a href="<?php echo esc_url( $vendor_url ); ?>"><?php echo esc_html( $vendor_name ); ?></a>
					<?php else : ?>
						<span><?php echo esc_html( $vendor_name ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</article>
