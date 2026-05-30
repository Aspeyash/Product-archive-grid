<?php
/**
 * Algolia data source. Mirrors enough of WP_Query that the existing widget
 * render path / load-more REST handler can iterate the results without
 * caring whether they came from the database or from Algolia.
 *
 * Algolia credentials come from the sibling ZYMARG Algolia Search plugin
 * (single source of truth). When that plugin is not active, callers should
 * silently fall back to WP_Query — see Query::build().
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Algolia query builder. Synchronous REST call with multi-host failover and
 * optional 5-minute transient cache. Returns a WP_Query subclass populated
 * with synthetic post objects.
 */
final class Algolia_Query extends \WP_Query {

	/** @var array Algolia hits keyed by ID. */
	private $hits = [];

	/** @var int Cached found_posts (Algolia nbHits). */
	private $algolia_nb_hits = 0;

	/** @var int Cached page (1-based). */
	private $algolia_page = 1;

	/** @var int Cached per_page. */
	private $algolia_per_page = 12;

	// =========================================================================
	// Public entry point.
	// =========================================================================

	/**
	 * Build an Algolia-backed query.
	 *
	 * Mirrors the contract of Query::build( $args, $page ): returns an object
	 * that exposes have_posts() / the_post() / rewind_posts() and the public
	 * counters (post_count, found_posts, max_num_pages) that the widget and
	 * the load-more REST handler rely on.
	 *
	 * @param array $args Sanitised settings (see Query::sanitize_settings).
	 * @param int   $page 1-based page number.
	 * @return \WP_Query
	 */
	public static function build( array $args, $page = 1 ) {
		$page     = max( 1, (int) $page );
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 12;

		// If the credential bridge isn't loaded, return an empty WP_Query.
		// Caller (Query::build) guards this; this is belt-and-braces.
		if ( ! function_exists( 'zymarg_algolia_get_setting' ) || ! function_exists( 'zymarg_algolia_index_name' ) ) {
			return new \WP_Query( [ 'post__in' => [ 0 ], 'post_type' => 'product' ] );
		}

		$app_id  = (string) zymarg_algolia_get_setting( 'app_id' );
		$api_key = (string) zymarg_algolia_get_setting( 'search_api_key' );
		$index   = (string) zymarg_algolia_index_name( 'products' );

		if ( '' === $app_id || '' === $api_key || '' === $index ) {
			return new \WP_Query( [ 'post__in' => [ 0 ], 'post_type' => 'product' ] );
		}

		$body = self::build_request_body( $args, $page, $per_page );

		$cache_enabled = (bool) apply_filters( 'pag_algolia_query_cache', true );
		$cache_key     = '';
		$response      = null;

		if ( $cache_enabled ) {
			$cache_key = 'pag_algolia_q_' . md5( wp_json_encode( [ $app_id, $index, $body ] ) );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				$response = $cached;
			}
		}

		if ( null === $response ) {
			$response = self::call_algolia( $app_id, $api_key, $index, $body );
			if ( $cache_enabled && is_array( $response ) && ! empty( $response['hits'] ) ) {
				$ttl = (int) apply_filters( 'pag_algolia_query_cache_ttl', 5 * MINUTE_IN_SECONDS );
				set_transient( $cache_key, $response, max( 30, $ttl ) );
			}
		}

		if ( ! is_array( $response ) || empty( $response['hits'] ) ) {
			$query                  = new self();
			$query->algolia_per_page = $per_page;
			$query->algolia_page     = $page;
			$query->algolia_nb_hits  = isset( $response['nbHits'] ) ? (int) $response['nbHits'] : 0;
			$query->hits             = [];
			$query->posts            = [];
			$query->post_count       = 0;
			$query->found_posts      = $query->algolia_nb_hits;
			$query->max_num_pages    = (int) ceil( $query->algolia_nb_hits / max( 1, $per_page ) );
			$query->current_post     = -1;
			$query->in_the_loop      = false;
			return $query;
		}

		$query                   = new self();
		$query->algolia_per_page = $per_page;
		$query->algolia_page     = $page;
		$query->algolia_nb_hits  = isset( $response['nbHits'] ) ? (int) $response['nbHits'] : count( $response['hits'] );
		$query->hits             = $response['hits'];
		$query->posts            = self::synthesize_posts( $response['hits'] );
		$query->post_count       = count( $query->posts );
		$query->found_posts      = $query->algolia_nb_hits;
		$query->max_num_pages    = (int) ceil( $query->algolia_nb_hits / max( 1, $per_page ) );
		$query->current_post     = -1;
		$query->in_the_loop      = false;

		return $query;
	}

	// =========================================================================
	// WP_Query overrides — the bare minimum to make the widget loop work.
	// =========================================================================

	/**
	 * Have-posts pointer check. Mirrors WP_Query but operates on $this->posts.
	 *
	 * @return bool
	 */
	public function have_posts() {
		if ( $this->current_post + 1 < $this->post_count ) {
			return true;
		}
		$this->in_the_loop = false;
		return false;
	}

	/**
	 * Advance the loop pointer and set up the global $post.
	 */
	public function the_post() {
		global $post;
		$this->in_the_loop = true;
		$this->current_post++;
		if ( isset( $this->posts[ $this->current_post ] ) ) {
			$post          = $this->posts[ $this->current_post ];
			$this->post    = $post;
			// We deliberately do NOT call setup_postdata() — its global $authordata
			// / $more / $multipage paths run extra DB queries on real WP_Post
			// objects, which we don't have here. The card template only reads
			// $post->ID and $post->_pag_algolia_data, so this is sufficient.
		}
	}

	/**
	 * Reset the loop pointer.
	 */
	public function rewind_posts() {
		$this->current_post = -1;
		$this->in_the_loop  = false;
	}

	// =========================================================================
	// Request building.
	// =========================================================================

	/**
	 * Translate sanitised widget args into an Algolia search request body.
	 *
	 * @param array $args     Sanitised settings.
	 * @param int   $page     1-based page.
	 * @param int   $per_page Hits per page.
	 * @return array
	 */
	private static function build_request_body( array $args, $page, $per_page ) {
		$mode = isset( $args['algolia_mode'] ) && '' !== $args['algolia_mode']
			? (string) $args['algolia_mode']
			: ( isset( $args['source'] ) ? (string) $args['source'] : 'all' );

		// Auto-detect "current_archive" when on a category/tag archive even if
		// the widget was set to current_search.
		if ( 'current_search' === $mode && function_exists( 'is_archive' ) && is_archive() && ! is_search() ) {
			$mode = 'current_archive';
		}

		// Auto-scope to vendor on Dokan store pages (matches Compat_Dokan).
		$auto_vendor_id = self::detect_vendor_id();
		if ( $auto_vendor_id && empty( $args['vendor_id'] ) ) {
			$args['vendor_id'] = $auto_vendor_id;
		}

		$query   = '';
		$filters = [];

		switch ( $mode ) {
			case 'featured':
				$filters[] = 'featured:true';
				break;

			case 'sale':
				$filters[] = 'on_sale:true';
				break;

			case 'best_sellers':
			case 'recent':
				// Sort handled below via index replica / sort param.
				break;

			case 'by_category':
				$cats = isset( $args['include_cats'] ) ? (array) $args['include_cats'] : [];
				if ( ! empty( $cats ) ) {
					$or = [];
					foreach ( $cats as $slug ) {
						// Filter on category_slugs (the indexer stores slugs in
						// this field specifically for filtering). The legacy
						// `categories` field stores human-readable names and
						// would silently miss whenever a slug != name.
						$or[] = 'category_slugs:"' . self::escape_filter_value( (string) $slug ) . '"';
					}
					$filters[] = '(' . implode( ' OR ', $or ) . ')';
				}
				break;

			case 'current_search':
				$query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;

			case 'current_archive':
				$obj = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
				if ( $obj instanceof \WP_Term ) {
					if ( 'product_cat' === $obj->taxonomy ) {
						$filters[] = 'category_slugs:"' . self::escape_filter_value( (string) $obj->slug ) . '"';
					} elseif ( 'product_tag' === $obj->taxonomy ) {
						$filters[] = 'tags:"' . self::escape_filter_value( (string) $obj->name ) . '"';
					}
				}
				break;

			case 'manual':
				$ids = isset( $args['include_ids'] ) ? (array) $args['include_ids'] : [];
				$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
				if ( ! empty( $ids ) ) {
					$or = [];
					foreach ( $ids as $id ) {
						$or[] = 'objectID:product_' . (int) $id;
						// Also try plain int objectID for indexers that don't
						// prefix — defence-in-depth.
						$or[] = 'objectID:' . (int) $id;
					}
					$filters[] = '(' . implode( ' OR ', $or ) . ')';
				} else {
					// Empty manual selection → no results.
					$filters[] = 'objectID:__pag_no_match__';
				}
				break;

			case 'vendor':
				if ( ! empty( $args['vendor_id'] ) ) {
					$filters[] = 'vendor_id:' . (int) $args['vendor_id'];
				}
				break;

			case 'all':
			default:
				if ( ! empty( $args['search_term'] ) ) {
					$query = (string) $args['search_term'];
				}
				if ( ! empty( $args['include_cats'] ) ) {
					$or = [];
					foreach ( (array) $args['include_cats'] as $slug ) {
						$or[] = 'category_slugs:"' . self::escape_filter_value( (string) $slug ) . '"';
					}
					$filters[] = '(' . implode( ' OR ', $or ) . ')';
				}
				break;
		}

		// Always-on vendor scope (auto-detected or explicit), unless the mode
		// already filtered by vendor.
		if ( 'vendor' !== $mode && ! empty( $args['vendor_id'] ) ) {
			$filters[] = 'vendor_id:' . (int) $args['vendor_id'];
		}

		// Exclude IDs.
		if ( ! empty( $args['exclude_ids'] ) ) {
			foreach ( (array) $args['exclude_ids'] as $id ) {
				$filters[] = 'NOT objectID:product_' . (int) $id;
			}
		}

		// Exclude categories.
		if ( ! empty( $args['exclude_cats'] ) ) {
			foreach ( (array) $args['exclude_cats'] as $slug ) {
				$filters[] = 'NOT category_slugs:"' . self::escape_filter_value( (string) $slug ) . '"';
			}
		}

		// Hide out of stock.
		if ( ! empty( $args['hide_oos'] ) ) {
			$filters[] = 'in_stock:true';
		}

		$body = [
			'query'                => $query,
			'page'                 => $page - 1, // Algolia pages are 0-based.
			'hitsPerPage'          => $per_page,
			'attributesToHighlight' => [],
			'attributesToSnippet'   => [],
			'clickAnalytics'        => false,
		];

		if ( ! empty( $filters ) ) {
			$body['filters'] = implode( ' AND ', $filters );
		}

		// Sort: best_sellers / recent / orderby. Translates to Algolia's
		// `customRanking` (which sits *after* the default ranking criteria
		// and lets us bias results without creating a replica index).
		//
		// IMPORTANT: Algolia's `ranking` parameter is for the BASE criteria
		// (typo, geo, words, proximity, attribute, exact, custom, filters)
		// and DOES NOT accept `field:desc` strings — sending one there is a
		// silent no-op (or 4xx) and the result order is undefined. The
		// correct knob is `customRanking` with the format `desc(field)` /
		// `asc(field)`. (Pre-1.1.0 builds had this wrong; fixed in 1.1.0.)
		$orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : '';
		$order_dir = ( isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'asc' : 'desc';
		$sort_field = '';
		$sort_dir   = 'desc';
		if ( 'best_sellers' === $mode ) {
			$sort_field = 'total_sales';
		} elseif ( 'recent' === $mode ) {
			$sort_field = 'date_created';
		} elseif ( 'price' === $orderby ) {
			$sort_field = 'price';
			$sort_dir   = $order_dir;
		} elseif ( 'rating' === $orderby ) {
			$sort_field = 'average_rating';
			$sort_dir   = $order_dir;
		} elseif ( 'popularity' === $orderby ) {
			$sort_field = 'total_sales';
			$sort_dir   = $order_dir;
		} elseif ( 'date' === $orderby ) {
			$sort_field = 'date_created';
			$sort_dir   = $order_dir;
		} elseif ( 'title' === $orderby ) {
			$sort_field = 'name';
			$sort_dir   = $order_dir;
		}
		if ( '' !== $sort_field ) {
			$body['customRanking'] = [ $sort_dir . '(' . $sort_field . ')' ];
		}

		/**
		 * Filter the Algolia request body before it's sent.
		 *
		 * @param array $body Request body.
		 * @param array $args Sanitised settings.
		 * @param int   $page 1-based page.
		 */
		return (array) apply_filters( 'pag_algolia_request_body', $body, $args, $page );
	}

	/**
	 * Send a search request to Algolia with multi-host failover.
	 *
	 * @param string $app_id  Application ID.
	 * @param string $api_key Search-only API key.
	 * @param string $index   Index name.
	 * @param array  $body    Request body.
	 * @return array|null Decoded response, or null on total failure.
	 */
	private static function call_algolia( $app_id, $api_key, $index, array $body ) {
		$hosts = [
			$app_id . '-dsn.algolia.net',
			$app_id . '-1.algolianet.com',
			$app_id . '-2.algolianet.com',
			$app_id . '-3.algolianet.com',
		];

		$payload = wp_json_encode( $body );
		$path    = '/1/indexes/' . rawurlencode( $index ) . '/query';

		$args = [
			'method'  => 'POST',
			'timeout' => 8,
			'headers' => [
				'Content-Type'             => 'application/json; charset=utf-8',
				'X-Algolia-Application-Id' => $app_id,
				'X-Algolia-API-Key'        => $api_key,
			],
			'body'    => $payload,
		];

		$last_error = '';
		foreach ( $hosts as $host ) {
			$url = 'https://' . $host . $path;
			$res = wp_remote_post( $url, $args );
			if ( is_wp_error( $res ) ) {
				$last_error = $res->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code < 200 || $code >= 300 ) {
				$last_error = 'HTTP ' . $code;
				// 4xx (except 429) → bad request; don't bother failing over.
				if ( $code >= 400 && $code < 500 && 429 !== $code ) {
					break;
				}
				continue;
			}
			$decoded = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			$last_error = 'Malformed JSON';
		}

		if ( $last_error && function_exists( 'error_log' ) ) {
			error_log( '[PAG Algolia] all hosts failed: ' . $last_error ); // phpcs:ignore
		}
		return null;
	}

	// =========================================================================
	// Synthetic post construction.
	// =========================================================================

	/**
	 * Convert Algolia hits into stdClass objects shaped enough like WP_Post
	 * for the widget loop and our card templates.
	 *
	 * @param array $hits Algolia hits.
	 * @return array
	 */
	private static function synthesize_posts( array $hits ) {
		$posts = [];
		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$id = isset( $hit['id'] ) ? (int) $hit['id'] : 0;
			if ( ! $id && isset( $hit['objectID'] ) ) {
				// objectID may be "product_123" or just "123".
				$obj = (string) $hit['objectID'];
				if ( preg_match( '/(\d+)/', $obj, $m ) ) {
					$id = (int) $m[1];
				}
			}
			if ( ! $id ) {
				continue;
			}
			$post                       = new \stdClass();
			$post->ID                   = $id;
			$post->post_type            = 'product';
			$post->post_status          = 'publish';
			$post->post_title           = isset( $hit['name'] ) ? (string) $hit['name'] : '';
			$post->post_name            = isset( $hit['slug'] ) ? (string) $hit['slug'] : '';
			$post->post_author          = isset( $hit['vendor_id'] ) ? (int) $hit['vendor_id'] : 0;
			$post->post_date            = '';
			$post->post_date_gmt        = '';
			$post->post_modified        = '';
			$post->post_modified_gmt    = '';
			$post->post_content         = isset( $hit['description'] ) ? (string) $hit['description'] : '';
			$post->post_excerpt         = isset( $hit['short_description'] ) ? (string) $hit['short_description'] : '';
			$post->post_parent          = 0;
			$post->menu_order           = 0;
			$post->comment_status       = 'closed';
			$post->ping_status          = 'closed';
			$post->filter               = 'raw';
			$post->permalink            = isset( $hit['permalink'] ) ? (string) $hit['permalink'] : '';
			$post->_pag_algolia_data    = $hit;
			$posts[]                    = $post;
		}
		return $posts;
	}

	// =========================================================================
	// Helpers.
	// =========================================================================

	/**
	 * Detect the current Dokan vendor ID, mirroring Compat_Dokan::scope_to_vendor.
	 *
	 * @return int 0 when not on a vendor store page or Dokan isn't loaded.
	 */
	private static function detect_vendor_id() {
		if ( ! function_exists( 'dokan_is_store_page' ) || ! dokan_is_store_page() ) {
			return 0;
		}
		if ( ! function_exists( 'dokan_get_store_user' ) ) {
			return 0;
		}
		$store_user = dokan_get_store_user( get_query_var( 'author' ) );
		if ( $store_user && method_exists( $store_user, 'get_id' ) ) {
			return (int) $store_user->get_id();
		}
		return 0;
	}

	/**
	 * Escape a value for inclusion in an Algolia filters string.
	 * Algolia uses double-quote-wrapped strings; escape internal quotes.
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	private static function escape_filter_value( $value ) {
		return str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value );
	}
}
