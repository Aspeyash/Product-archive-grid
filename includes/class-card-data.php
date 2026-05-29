<?php
/**
 * Card-data normaliser. Returns a single flat array shape that card
 * sub-templates can read from regardless of whether the underlying source
 * is a real WC_Product (default WP_Query path) or a synthetic post backed
 * by an Algolia hit (v1.1.0 Algolia data-source path).
 *
 * Why a global function: card sub-templates are simple PHP files that take
 * a $product variable; rather than refactor their signatures, we expose a
 * single function `pag_card_data()` that templates can call to get a
 * source-agnostic data array.
 *
 * @package ProductArchiveGrid
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'pag_card_data' ) ) {
	/**
	 * Build a normalised card-data array.
	 *
	 * Order of preference:
	 *   1. If global $post has _pag_algolia_data, build from the Algolia hit.
	 *   2. Else if a WC_Product is supplied, build from the WC API.
	 *   3. Else attempt to resolve a WC_Product from $post->ID.
	 *
	 * Always returns an array with the same keys; missing values are
	 * defaulted so templates can read freely without isset() checks.
	 *
	 * @param \WC_Product|\WP_Post|null $product_or_post Optional WC_Product
	 *                                                   to derive data from,
	 *                                                   or a WP_Post when the
	 *                                                   caller has the global
	 *                                                   post handy. Falls back
	 *                                                   to global $post.
	 * @return array
	 */
	function pag_card_data( $product_or_post = null ) {
		$defaults = [
			'is_algolia'   => false,
			'id'           => 0,
			'name'         => '',
			'slug'         => '',
			'permalink'    => '',
			'thumbnail'    => '',
			'price_html'   => '',
			'regular_price' => 0.0,
			'sale_price'   => 0.0,
			'on_sale'      => false,
			'in_stock'     => false,
			'stock_qty'    => null,
			'total_sales'  => 0,
			'rating'       => 0.0,
			'review_count' => 0,
			'product_type' => 'simple',
			'vendor_id'    => 0,
			'vendor_name'  => '',
			'vendor_url'   => '',
			'short_description' => '',
		];

		// Prefer Algolia data on the current global $post when available.
		global $post;
		if ( isset( $post ) && is_object( $post ) && isset( $post->_pag_algolia_data ) && is_array( $post->_pag_algolia_data ) ) {
			$hit = $post->_pag_algolia_data;
			return array_merge(
				$defaults,
				[
					'is_algolia'   => true,
					'id'           => isset( $hit['id'] ) ? (int) $hit['id'] : (int) ( $post->ID ?? 0 ),
					'name'         => isset( $hit['name'] ) ? (string) $hit['name'] : (string) ( $post->post_title ?? '' ),
					'slug'         => isset( $hit['slug'] ) ? (string) $hit['slug'] : (string) ( $post->post_name ?? '' ),
					'permalink'    => isset( $hit['permalink'] ) ? (string) $hit['permalink'] : '',
					'thumbnail'    => isset( $hit['thumbnail'] ) ? (string) $hit['thumbnail'] : '',
					'price_html'   => isset( $hit['price_html'] ) ? (string) $hit['price_html'] : '',
					'regular_price' => isset( $hit['regular_price'] ) ? (float) $hit['regular_price'] : 0.0,
					'sale_price'   => ( isset( $hit['sale_price'] ) && null !== $hit['sale_price'] ) ? (float) $hit['sale_price'] : 0.0,
					'on_sale'      => ! empty( $hit['on_sale'] ),
					'in_stock'     => ! empty( $hit['in_stock'] ),
					'stock_qty'    => isset( $hit['stock_quantity'] ) && null !== $hit['stock_quantity'] ? (int) $hit['stock_quantity'] : null,
					'total_sales'  => isset( $hit['total_sales'] ) ? (int) $hit['total_sales'] : 0,
					'rating'       => isset( $hit['average_rating'] ) ? (float) $hit['average_rating'] : 0.0,
					'review_count' => isset( $hit['review_count'] ) ? (int) $hit['review_count'] : 0,
					'product_type' => isset( $hit['product_type'] ) ? (string) $hit['product_type'] : 'simple',
					'vendor_id'    => isset( $hit['vendor_id'] ) ? (int) $hit['vendor_id'] : 0,
					'vendor_name'  => isset( $hit['vendor_name'] ) ? (string) $hit['vendor_name'] : '',
					'vendor_url'   => isset( $hit['vendor_url'] ) ? (string) $hit['vendor_url'] : '',
					'short_description' => isset( $hit['short_description'] ) ? (string) $hit['short_description'] : '',
				]
			);
		}

		// WC_Product path.
		$product = null;
		if ( $product_or_post instanceof \WC_Product ) {
			$product = $product_or_post;
		} elseif ( $product_or_post && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_or_post );
		} else {
			// Fall back to the global post.
			$pid = ( isset( $post ) && is_object( $post ) && isset( $post->ID ) ) ? (int) $post->ID : 0;
			if ( $pid && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $pid );
			}
		}

		if ( ! $product instanceof \WC_Product ) {
			return $defaults;
		}

		$thumb_id = $product->get_image_id();
		$thumb    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' ) : '';
		if ( ! $thumb && function_exists( 'wc_placeholder_img_src' ) ) {
			$thumb = wc_placeholder_img_src( 'woocommerce_thumbnail' );
		}

		$sale = $product->get_sale_price();

		return array_merge(
			$defaults,
			[
				'is_algolia'   => false,
				'id'           => (int) $product->get_id(),
				'name'         => (string) $product->get_name(),
				'slug'         => (string) $product->get_slug(),
				'permalink'    => (string) $product->get_permalink(),
				'thumbnail'    => (string) $thumb,
				'price_html'   => (string) $product->get_price_html(),
				'regular_price' => (float) $product->get_regular_price(),
				'sale_price'   => '' !== $sale ? (float) $sale : 0.0,
				'on_sale'      => (bool) $product->is_on_sale(),
				'in_stock'     => (bool) $product->is_in_stock(),
				'stock_qty'    => $product->get_stock_quantity(),
				'total_sales'  => (int) $product->get_total_sales(),
				'rating'       => (float) $product->get_average_rating(),
				'review_count' => (int) $product->get_review_count(),
				'product_type' => (string) $product->get_type(),
				'vendor_id'    => (int) get_post_field( 'post_author', $product->get_id() ),
				'short_description' => (string) wp_strip_all_tags( $product->get_short_description() ),
			]
		);
	}
}
