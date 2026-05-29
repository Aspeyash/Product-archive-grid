<?php
/**
 * Quick View modal skeleton — printed once at the end of <body>.
 * Hydrated by assets/js/modal.js.
 *
 * @package ProductArchiveGrid
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	class="pag-modal"
	id="pag-modal"
	role="dialog"
	aria-modal="true"
	aria-labelledby="pag-modal-title"
	aria-hidden="true"
	hidden
>
	<div class="pag-modal__overlay" data-pag-close></div>

	<div class="pag-modal__panel" tabindex="-1">
		<button type="button" class="pag-modal__close" data-pag-close aria-label="<?php echo esc_attr__( 'Close quick view', 'product-archive-grid' ); ?>">
			<?php echo \PAG\Template::icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>

		<div class="pag-modal__loading" aria-live="polite">
			<span class="pag-modal__spinner" aria-hidden="true"></span>
			<span><?php esc_html_e( 'Loading…', 'product-archive-grid' ); ?></span>
		</div>

		<div class="pag-modal__error" role="alert" hidden></div>

		<div class="pag-modal__content" hidden>
			<div class="pag-modal__media">
				<div class="pag-modal__hero">
					<img class="pag-modal__hero-img" alt="" />
				</div>
				<div class="pag-modal__thumbs" role="tablist" aria-label="<?php echo esc_attr__( 'Product images', 'product-archive-grid' ); ?>"></div>
			</div>

			<div class="pag-modal__body">
				<h2 class="pag-modal__title" id="pag-modal-title"></h2>

				<div class="pag-modal__rating" hidden>
					<span class="pag-modal__stars"></span>
					<span class="pag-modal__rating-value"></span>
					<span class="pag-modal__review-count"></span>
				</div>

				<div class="pag-modal__price"></div>

				<div class="pag-modal__sku" hidden>
					<span class="pag-modal__sku-label"><?php esc_html_e( 'SKU:', 'product-archive-grid' ); ?></span>
					<span class="pag-modal__sku-value"></span>
				</div>

				<div class="pag-modal__desc"></div>

				<div class="pag-modal__variations" hidden></div>

				<div class="pag-modal__actions">
					<div class="pag-modal__qty">
						<button type="button" class="pag-modal__qty-btn" data-pag-qty="-1" aria-label="<?php echo esc_attr__( 'Decrease quantity', 'product-archive-grid' ); ?>">−</button>
						<input
							type="number"
							class="pag-modal__qty-input"
							value="1"
							min="1"
							inputmode="numeric"
							aria-label="<?php echo esc_attr__( 'Quantity', 'product-archive-grid' ); ?>"
						/>
						<button type="button" class="pag-modal__qty-btn" data-pag-qty="1" aria-label="<?php echo esc_attr__( 'Increase quantity', 'product-archive-grid' ); ?>">+</button>
					</div>

					<button type="button" class="pag-modal__add-to-cart" disabled>
						<span class="pag-modal__atc-icon" aria-hidden="true"><?php echo \PAG\Template::icon( 'cart-plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="pag-modal__atc-label"><?php esc_html_e( 'Add to cart', 'product-archive-grid' ); ?></span>
					</button>

					<button type="button" class="pag-modal__buy-now" disabled>
						<?php esc_html_e( 'Buy now', 'product-archive-grid' ); ?>
					</button>
				</div>

				<a class="pag-modal__permalink" href="#" target="_self">
					<?php esc_html_e( 'View full details →', 'product-archive-grid' ); ?>
				</a>
			</div>
		</div>
	</div>
</div>
