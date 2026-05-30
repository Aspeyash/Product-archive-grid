<?php
/**
 * Product query builder. Centralises WP_Query construction so the widget
 * (page 1) and the load-more endpoint (page N) produce identical results.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless query helper.
 */
final class Query {

	/**
	 * Resolve the user-facing per_page selection into a concrete integer.
	 *
	 * Accepts:
	 *   - 'none' / '' / 0   → 100 (the hard cap; "None" in the widget UI)
	 *   - any positive int  → clamped to [1, 100]
	 *
	 * The 100 cap is a safety guard. WP_Query with posts_per_page = -1 on a
	 * 50K-product catalog will exhaust PHP memory and crash the page; Algolia
	 * also caps hitsPerPage at 1000 per request. 100 is a generous practical
	 * ceiling that keeps the front-end render fast on every catalog size.
	 *
	 * Lives on Query (not the widget) so both the widget render path AND the
	 * load-more REST handler can call it. The widget class isn't loaded on
	 * REST requests because Elementor's widget registration only fires on
	 * frontend page renders.
	 *
	 * @param mixed $raw Raw value (string from SELECT control or int).
	 * @return int 1..100
	 */
	public static function resolve_per_page( $raw ) {
		if ( 'none' === $raw || '' === $raw || null === $raw ) {
			return 100;
		}
		$n = (int) $raw;
		if ( $n <= 0 ) {
			return 100;
		}
		return max( 1, min( 100, $n ) );
	}

	/**
	 * Sanitise the settings payload that crosses trust boundaries (load-more
	 * REST request). Returns only known keys with strictly-typed values.
	 *
	 * @param array $raw Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( array $raw ) {
		$out = [];

		$out['source']         = self::pick(
			$raw['source'] ?? 'all',
			[ 'all', 'sale', 'featured', 'best_sellers', 'recent', 'by_category', 'manual', 'current_search', 'algolia', 'vendor' ]
		);
		$out['per_page']       = self::resolve_per_page( $raw['per_page'] ?? 12 );
		$out['orderby']        = self::pick(
			$raw['orderby'] ?? 'date',
			[ 'date', 'title', 'price', 'popularity', 'rating', 'menu_order', 'rand' ]
		);
		$out['order']          = self::pick( strtoupper( (string) ( $raw['order'] ?? 'DESC' ) ), [ 'ASC', 'DESC' ] );
		$out['hide_oos']       = ! empty( $raw['hide_oos'] );
		$out['include_ids']    = Security::sanitize_id_list( $raw['include_ids'] ?? '' );
		$out['exclude_ids']    = Security::sanitize_id_list( $raw['exclude_ids'] ?? '' );
		$out['include_cats']   = self::sanitize_term_list( $raw['include_cats'] ?? '', 'product_cat' );
		$out['exclude_cats']   = self::sanitize_term_list( $raw['exclude_cats'] ?? '', 'product_cat' );
		$out['search_term']    = isset( $raw['search_term'] ) ? sanitize_text_field( (string) $raw['search_term'] ) : '';
		$out['vendor_id']      = isset( $raw['vendor_id'] ) ? absint( $raw['vendor_id'] ) : 0;

		// Algolia data-source routing (v1.1.0). When 'algolia' is selected
		// we preserve the user's underlying source mode (featured / sale /
		// best_sellers / etc) in 'algolia_mode' so Algolia_Query can pick the
		// right filter. Allowed values match the SOURCE allow-list above
		// plus 'current_archive' (auto-detected by Algolia_Query).
		$out['algolia_mode']   = self::pick(
			$raw['algolia_mode'] ?? '',
			[ '', 'all', 'sale', 'featured', 'best_sellers', 'recent', 'by_category', 'manual', 'current_search', 'current_archive', 'vendor' ]
		);

		// Toggles flow through but aren't query-relevant; preserved for render.
		$display_keys = [
			'show_image', 'show_discount_badge', 'show_stock_badge', 'show_quick_view',
			'show_wishlist', 'show_title', 'show_rating', 'show_rating_value', 'show_sold',
			'show_price', 'show_old_price', 'show_add_to_cart', 'show_vendor',
			'image_size', 'image_hover_swap', 'discount_format',
		];
		foreach ( $display_keys as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = is_string( $raw[ $k ] ) ? sanitize_text_field( $raw[ $k ] ) : $raw[ $k ];
			}
		}

		return $out;
	}

	/**
	 * Build a WP_Query for products.
	 *
	 * @param array $settings Sanitised settings.
	 * @param int   $page     Page number (1-based).
	 * @return \WP_Query
	 */
	public static function build( array $settings, $page = 1 ) {
		$page = max( 1, (int) $page );

		// v1.1.0 — Algolia data-source router. Delegate to Algolia_Query when
		// the widget opted in AND the credentials bridge function exists.
		// Silently falls through to WP_Query if zymarg-algolia-search isn't
		// active, so a widget set to "use Algolia" never breaks the page.
		if ( 'algolia' === ( $settings['source'] ?? '' ) && function_exists( 'zymarg_algolia_get_setting' ) ) {
			if ( ! class_exists( __NAMESPACE__ . '\\Algolia_Query' ) ) {
				$path = PAG_INCLUDES_DIR . 'class-algolia-query.php';
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
			if ( class_exists( __NAMESPACE__ . '\\Algolia_Query' ) ) {
				return Algolia_Query::build( $settings, $page );
			}
		}

		// Inherit the main query when we're on the WC search archive and the
		// widget was set to "current_search".
		if ( 'current_search' === ( $settings['source'] ?? '' ) && ( is_search() || is_archive() ) ) {
			global $wp_query;
			if ( $wp_query instanceof \WP_Query ) {
				return $wp_query;
			}
		}

		$args = [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'paged'               => $page,
			'posts_per_page'      => (int) $settings['per_page'],
			'tax_query'           => [], // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_query'          => [], // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'       => false,
		];

		// Order.
		switch ( $settings['orderby'] ) {
			case 'price':
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['orderby']  = 'meta_value_num';
				$args['order']    = $settings['order'];
				break;
			case 'popularity':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['orderby']  = 'meta_value_num';
				$args['order']    = $settings['order'];
				break;
			case 'rating':
				$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['orderby']  = 'meta_value_num';
				$args['order']    = $settings['order'];
				break;
			default:
				$args['orderby'] = $settings['orderby'];
				$args['order']   = $settings['order'];
				break;
		}

		// Source-specific filters.
		switch ( $settings['source'] ) {
			case 'featured':
				$args['tax_query'][] = [
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => 'featured',
				];
				break;
			case 'sale':
				$ids                  = function_exists( 'wc_get_product_ids_on_sale' ) ? wc_get_product_ids_on_sale() : [];
				$args['post__in']     = ! empty( $ids ) ? $ids : [ 0 ];
				break;
			case 'best_sellers':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			case 'recent':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'manual':
				if ( ! empty( $settings['include_ids'] ) ) {
					$args['post__in'] = $settings['include_ids'];
					$args['orderby']  = 'post__in';
				} else {
					$args['post__in'] = [ 0 ];
				}
				break;
			case 'by_category':
				if ( ! empty( $settings['include_cats'] ) ) {
					$args['tax_query'][] = [
						'taxonomy' => 'product_cat',
						'field'    => 'slug',
						'terms'    => $settings['include_cats'],
					];
				}
				break;
			case 'all':
			default:
				if ( ! empty( $settings['include_cats'] ) ) {
					$args['tax_query'][] = [
						'taxonomy' => 'product_cat',
						'field'    => 'slug',
						'terms'    => $settings['include_cats'],
					];
				}
				if ( ! empty( $settings['search_term'] ) ) {
					$args['s'] = $settings['search_term'];
				}
				break;
		}

		// Include/exclude additions (apply on top of source filters).
		if ( 'manual' !== $settings['source'] && ! empty( $settings['include_ids'] ) ) {
			$args['post__in'] = $settings['include_ids'];
		}
		if ( ! empty( $settings['exclude_ids'] ) ) {
			$args['post__not_in'] = $settings['exclude_ids'];
		}
		if ( ! empty( $settings['exclude_cats'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $settings['exclude_cats'],
				'operator' => 'NOT IN',
			];
		}

		// Hide out of stock.
		if ( ! empty( $settings['hide_oos'] ) ) {
			$args['meta_query'][] = [
				'key'     => '_stock_status',
				'value'   => 'instock',
				'compare' => '=',
			];
		}

		// Always exclude catalog-hidden products.
		$args['tax_query'][] = [
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => [ 'exclude-from-catalog' ],
			'operator' => 'NOT IN',
		];

		// Dokan vendor scope (if vendor_id passed or auto-detected).
		if ( ! empty( $settings['vendor_id'] ) ) {
			$args['author'] = (int) $settings['vendor_id'];
		}

		/**
		 * Filter the final query args.
		 *
		 * @param array $args     WP_Query args.
		 * @param array $settings Settings used to build the query.
		 * @param int   $page     Page number.
		 */
		$args = (array) apply_filters( 'pag_query_args', $args, $settings, $page );

		return new \WP_Query( $args );
	}

	/**
	 * Pick a value from an allow-list.
	 *
	 * @param string $value   Candidate.
	 * @param array  $allowed Allow-list.
	 * @return string
	 */
	private static function pick( $value, array $allowed ) {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}

	/**
	 * Sanitise a list of taxonomy term slugs against an existing taxonomy.
	 *
	 * @param mixed  $raw      String or array.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[]
	 */
	private static function sanitize_term_list( $raw, $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}
		if ( is_string( $raw ) ) {
			$parts = array_map( 'trim', explode( ',', $raw ) );
		} elseif ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			return [];
		}

		$parts = array_map( 'sanitize_title', $parts );
		$parts = array_values( array_filter( $parts ) );
		return array_slice( $parts, 0, 100 );
	}
}
