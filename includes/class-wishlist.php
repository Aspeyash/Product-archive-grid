<?php
/**
 * Custom Wishlist module.
 *
 * Storage:
 *   - logged-in: user_meta `pag_wishlist` (array of int product IDs)
 *   - guest:     WC()->session 'pag_wishlist' + localStorage backup (client)
 *
 * Login merge:
 *   - On wp_login, merge any guest WC-session wishlist into the user's
 *     persistent wishlist, dedup, then clear the session copy.
 *
 * Persistence guarantees:
 *   - Logged-in: persists indefinitely (user_meta).
 *   - Guest: persists for the duration of the WC session (default 48h via
 *     WC config) + localStorage backup that the client re-syncs on page load.
 *
 * REST:
 *   GET    /wishlist           → current wishlist + product previews
 *   POST   /wishlist/toggle    → add/remove a product, returns new state
 *   POST   /wishlist/clear     → empty the wishlist
 *   POST   /wishlist/sync      → merge a client-side list (guest only)
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Wishlist storage + REST + login merge.
 */
class Wishlist {

	const META_KEY    = 'pag_wishlist';
	const SESSION_KEY = 'pag_wishlist';
	const MAX_ITEMS   = 200;

	/** @var REST_API */
	private $rest_api;

	/** @var Rate_Limiter */
	private $rate_limiter;

	/**
	 * @param REST_API     $rest_api    REST router.
	 * @param Rate_Limiter $rate_limiter Limiter.
	 */
	public function __construct( REST_API $rest_api, Rate_Limiter $rate_limiter ) {
		$this->rest_api     = $rest_api;
		$this->rate_limiter = $rate_limiter;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'wp_login', [ $this, 'on_login_merge' ], 20, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 25 );
	}

	/**
	 * Enqueue wishlist JS.
	 */
	public function enqueue() {
		wp_enqueue_script( 'pag-wishlist' );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		$perm = [ $this->rest_api, 'permission_public' ];

		register_rest_route(
			PAG_REST_NAMESPACE,
			'/wishlist',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_get' ],
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			PAG_REST_NAMESPACE,
			'/wishlist/toggle',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_toggle' ],
				'permission_callback' => $perm,
				'args'                => [
					'product_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			PAG_REST_NAMESPACE,
			'/wishlist/clear',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_clear' ],
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			PAG_REST_NAMESPACE,
			'/wishlist/sync',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_sync' ],
				'permission_callback' => $perm,
				'args'                => [
					'ids' => [
						'required' => true,
						'type'     => 'array',
					],
				],
			]
		);
	}

	// =========================================================================
	// Public read API (also used by the wishlist-button template)
	// =========================================================================

	/**
	 * Whether a wishlist contains a product.
	 *
	 * @param int $user_id    Current user ID (0 for guest).
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function contains( $user_id, $product_id ) {
		$ids = self::read_for( (int) $user_id );
		return in_array( (int) $product_id, $ids, true );
	}

	/**
	 * Read the current user's (or session's) wishlist.
	 *
	 * @param int $user_id User ID (0 for guest).
	 * @return int[]
	 */
	public static function read_for( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id ) {
			$ids = get_user_meta( $user_id, self::META_KEY, true );
			$ids = is_array( $ids ) ? $ids : [];
		} else {
			$ids = [];
			if ( function_exists( 'WC' ) && WC()->session ) {
				$ids = (array) WC()->session->get( self::SESSION_KEY );
			}
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return array_slice( $ids, 0, self::MAX_ITEMS );
	}

	/**
	 * Persist the wishlist for current visitor.
	 *
	 * @param int[] $ids Product IDs.
	 */
	private function save( array $ids ) {
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$ids     = array_slice( $ids, 0, self::MAX_ITEMS );
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_KEY, $ids );
			return;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $ids );
		}
	}

	// =========================================================================
	// REST handlers
	// =========================================================================

	/**
	 * GET /wishlist
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rl = $this->rate_limiter->check( 'wishlist_get' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$ids   = self::read_for( get_current_user_id() );
		$items = [];
		foreach ( $ids as $pid ) {
			$p = Security::get_validated_product( $pid );
			if ( ! $p ) {
				continue;
			}
			$items[] = [
				'id'         => $p->get_id(),
				'name'       => $p->get_name(),
				'permalink'  => $p->get_permalink(),
				'image'      => wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ),
				'price_html' => Template::price_html( $p ),
			];
		}

		return rest_ensure_response(
			[
				'success' => true,
				'ids'     => $ids,
				'items'   => $items,
				'count'   => count( $ids ),
			]
		);
	}

	/**
	 * POST /wishlist/toggle
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_toggle( $request ) {
		$rl = $this->rate_limiter->check( 'wishlist_toggle' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$pid     = (int) $request->get_param( 'product_id' );
		$product = Security::get_validated_product( $pid );
		if ( ! $product || ! $product->is_visible() ) {
			return new \WP_Error(
				'pag_invalid_product',
				__( 'Invalid product.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}

		$ids   = self::read_for( get_current_user_id() );
		$index = array_search( $pid, $ids, true );
		$state = false;
		if ( false === $index ) {
			$ids[] = $pid;
			$state = true;
		} else {
			array_splice( $ids, (int) $index, 1 );
		}

		$this->save( $ids );

		return rest_ensure_response(
			[
				'success'    => true,
				'in_list'    => $state,
				'product_id' => $pid,
				'count'      => count( $ids ),
			]
		);
	}

	/**
	 * POST /wishlist/clear
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_clear( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rl = $this->rate_limiter->check( 'wishlist_toggle' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}
		$this->save( [] );
		return rest_ensure_response( [ 'success' => true, 'count' => 0 ] );
	}

	/**
	 * POST /wishlist/sync
	 *
	 * Used by the client to push localStorage IDs back to the server so they
	 * survive WC session expiry on the same browser. Guests only — for
	 * logged-in users user_meta is the source of truth.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_sync( $request ) {
		$rl = $this->rate_limiter->check( 'wishlist_toggle' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$client_ids = Security::sanitize_id_list( $request->get_param( 'ids' ) );

		$server_ids = self::read_for( get_current_user_id() );
		$merged     = array_values( array_unique( array_merge( $server_ids, $client_ids ) ) );
		$this->save( $merged );

		return rest_ensure_response(
			[
				'success' => true,
				'ids'     => self::read_for( get_current_user_id() ),
			]
		);
	}

	// =========================================================================
	// Login merge
	// =========================================================================

	/**
	 * Merge guest WC-session wishlist into the user's persistent wishlist on
	 * login, then empty the session copy.
	 *
	 * @param string   $user_login Login name.
	 * @param \WP_User $user       User.
	 */
	public function on_login_merge( $user_login, $user ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$guest = [];
		if ( function_exists( 'WC' ) && WC()->session ) {
			$guest = (array) WC()->session->get( self::SESSION_KEY );
		}
		$existing = (array) get_user_meta( $user->ID, self::META_KEY, true );
		$merged   = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', array_merge( $existing, $guest ) )
				)
			)
		);
		$merged = array_slice( $merged, 0, self::MAX_ITEMS );
		update_user_meta( $user->ID, self::META_KEY, $merged );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, [] );
		}
	}
}
