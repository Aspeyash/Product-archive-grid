<?php
/**
 * Heading section above the grid.
 *
 * @package ProductArchiveGrid
 *
 * @var array $settings Sanitised settings.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $settings['show_heading'] ) ) {
	return;
}

$tag   = $settings['heading_tag']    ?? 'h2';
$title = $settings['heading_text']   ?? '';
$sub   = $settings['heading_subtext'] ?? '';

if ( '' === trim( wp_strip_all_tags( $title . $sub ) ) ) {
	return;
}

$tag = in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div' ], true ) ? $tag : 'h2';
?>
<header class="pag-heading">
	<?php if ( '' !== $title ) : ?>
		<<?php echo esc_attr( $tag ); ?> class="pag-heading__title"><?php echo wp_kses_post( $title ); ?></<?php echo esc_attr( $tag ); ?>>
	<?php endif; ?>

	<?php if ( '' !== $sub ) : ?>
		<p class="pag-heading__sub"><?php echo wp_kses_post( $sub ); ?></p>
	<?php endif; ?>
</header>
