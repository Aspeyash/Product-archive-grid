<?php
/**
 * Star rating + numeric value. Hidden entirely when there are no reviews.
 *
 * v1.1.0: now reads from $data so the same template works on the Algolia
 * data path.
 *
 * @package ProductArchiveGrid
 *
 * @var \WC_Product|null $product
 * @var array            $data
 * @var array            $settings
 */

defined( 'ABSPATH' ) || exit;

$avg   = (float) ( $data['rating'] ?? 0 );
$count = (int)   ( $data['review_count'] ?? 0 );

if ( $avg <= 0 || $count <= 0 ) {
	return;
}

$show_value = ! empty( $settings['show_rating_value'] );
$rounded    = max( 0, min( 5, (int) round( $avg ) ) );
?>
<div class="pag-rating" aria-label="<?php
	echo esc_attr(
		sprintf(
			/* translators: 1: rating, 2: max */
			__( 'Rated %1$s out of %2$d', 'product-archive-grid' ),
			number_format_i18n( $avg, 1 ),
			5
		)
	);
?>">
	<span class="pag-rating__stars" aria-hidden="true">
		<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
			<span class="pag-rating__star <?php echo $i <= $rounded ? 'is-filled' : ''; ?>">
				<?php echo \PAG\Template::icon( $i <= $rounded ? 'star' : 'star-empty' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		<?php endfor; ?>
	</span>
	<?php if ( $show_value ) : ?>
		<span class="pag-rating__value"><?php echo esc_html( number_format_i18n( $avg, 1 ) ); ?></span>
	<?php endif; ?>
</div>
