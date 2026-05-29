<?php
/**
 * Quick View module — REST endpoint that serialises a product's data for the
 * modal renderer (image gallery, price, attributes with swatch metadata, and
 * the full variation matrix for variable products).
 *
 * Variation swatches: we detect the swatch type per attribute term:
 *   color   — term meta 'product_attribute_color' / 'color' / '_color'
 *   image   — term meta 'thumbnail_id' (a custom-uploaded image)
 *   label   — fallback (button with the term name)
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Quick View REST API + asset enqueue.
 */
class Quick_View {

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
		add_action( 'wp_footer', [ $this, 'print_modal_template' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 25 );
	}

	/**
	 * Auto-enqueue the modal stylesheet/script if a PAG widget will render on
	 * this page. We can't reliably detect the widget here, so we register
	 * eagerly when the page is a product/archive/search.
	 */
	public function enqueue() {
		// Stylesheet/script are registered by Assets; we just enqueue.
		wp_enqueue_style( 'pag-modal' );
		wp_enqueue_script( 'pag-modal' );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			PAG_REST_NAMESPACE,
			'/quick-view',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_get' ],
				'permission_callback' => [ $this->rest_api, 'permission_public' ],
				'args'                => [
					'product_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * GET /quick-view handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( $request ) {
		$rl = $this->rate_limiter->check( 'quick_view' );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$product_id = (int) $request->get_param( 'product_id' );
		$product    = Security::get_validated_product( $product_id );
		if ( ! $product || ! $product->is_visible() ) {
			return new \WP_Error(
				'pag_invalid_product',
				__( 'Invalid product.', 'product-archive-grid' ),
				[ 'status' => 400 ]
			);
		}

		// Image + gallery as usable URLs.
		$image_id = (int) $product->get_image_id();
		$gallery  = array_map(
			static function ( $id ) {
				return [
					'id'    => (int) $id,
					'thumb' => wp_get_attachment_image_url( $id, 'medium' ),
					'large' => wp_get_attachment_image_url( $id, 'large' ),
					'alt'   => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				];
			},
			array_merge( [ $image_id ], (array) $product->get_gallery_image_ids() )
		);
		$gallery = array_values( array_filter( $gallery, static fn( $g ) => ! empty( $g['large'] ) ) );

		$payload = [
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'permalink'         => $product->get_permalink(),
			'sku'               => $product->get_sku(),
			'short_description' => apply_filters( 'woocommerce_short_description', $product->get_short_description() ),
			'price_html'        => Template::price_html( $product ),
			'gallery'           => $gallery,
			'rating'            => (float) $product->get_average_rating(),
			'review_count'      => (int) $product->get_review_count(),
			'in_stock'          => $product->is_in_stock(),
			'purchasable'       => $product->is_purchasable(),
			'type'              => $product->get_type(),
			'is_variable'       => $product->is_type( 'variable' ),
			'add_to_cart_text'  => $product->add_to_cart_text(),
			'attributes'        => [],
			'variations'        => [],
		];

		if ( $product->is_type( 'variable' ) ) {
			$payload['attributes'] = $this->serialize_attributes( $product );
			$payload['variations'] = $this->serialize_variations( $product );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Serialise the variation attributes (axes) with swatch metadata per term.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array
	 */
	private function serialize_attributes( $product ) {
		$out          = [];
		$variation_attrs = $product->get_variation_attributes();

		foreach ( $product->get_attributes() as $attr_key => $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			$is_taxonomy = $attr->is_taxonomy();
			$tax         = $is_taxonomy ? $attr->get_name() : '';
			$label       = wc_attribute_label( $attr->get_name(), $product );
			$slug        = $is_taxonomy ? $attr->get_name() : sanitize_title( $attr->get_name() );

			$terms = [];
			if ( $is_taxonomy ) {
				$attr_terms = wp_get_post_terms( $product->get_id(), $tax );
				if ( is_wp_error( $attr_terms ) ) {
					continue;
				}
				foreach ( $attr_terms as $term ) {
					$terms[] = $this->term_swatch( $term, $tax );
				}
			} else {
				// Custom attribute (string-based).
				foreach ( $attr->get_options() as $opt ) {
					$terms[] = [
						'slug'  => sanitize_title( $opt ),
						'label' => $opt,
						'type'  => 'label',
						'value' => $opt,
					];
				}
			}

			$out[] = [
				'name'    => $slug,                        // attribute_pa_color or attribute_size.
				'key'     => 'attribute_' . sanitize_title( $is_taxonomy ? $tax : $attr->get_name() ),
				'label'   => $label,
				'options' => $terms,
			];
		}

		return $out;
	}

	/**
	 * Detect a term's swatch type using a small set of common term-meta keys.
	 *
	 * @param \WP_Term $term  Term.
	 * @param string   $tax   Taxonomy.
	 * @return array
	 */
	private function term_swatch( $term, $tax ) {
		$entry = [
			'slug'  => $term->slug,
			'label' => $term->name,
			'type'  => 'label',
			'value' => $term->name,
		];

		$color_meta_keys = [ 'product_attribute_color', 'color', '_color', 'pa_color_color' ];
		foreach ( $color_meta_keys as $k ) {
			$color = get_term_meta( $term->term_id, $k, true );
			if ( $color && preg_match( '/^#?[0-9a-fA-F]{3,8}$/', $color ) ) {
				$entry['type']  = 'color';
				$entry['value'] = ( strpos( $color, '#' ) === 0 ? $color : '#' . $color );
				return $entry;
			}
		}

		$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
			if ( $src ) {
				$entry['type']  = 'image';
				$entry['value'] = $src;
				return $entry;
			}
		}

		// Color guessed by name (last resort) for common color attributes.
		if ( 'pa_color' === $tax || 'pa_colour' === $tax ) {
			$entry['type']  = 'color';
			$entry['value'] = $this->guess_named_color( $term->name );
		}

		return $entry;
	}

	/**
	 * Map a small set of common color names to hex. Returns '#cccccc' for unknowns.
	 *
	 * @param string $name Color name.
	 * @return string Hex color.
	 */
	private function guess_named_color( $name ) {
		$map = [
			'black' => '#000000', 'white' => '#ffffff', 'red' => '#dc2626',
			'green' => '#16a34a', 'blue' => '#2563eb', 'yellow' => '#facc15',
			'orange' => '#ea580c', 'pink' => '#ec4899', 'purple' => '#9333ea',
			'gray' => '#6b7280', 'grey' => '#6b7280', 'brown' => '#92400e',
			'beige' => '#e7d2b1', 'navy' => '#1e3a8a', 'teal' => '#0d9488',
		];
		$lc = strtolower( $name );
		return $map[ $lc ] ?? '#cccccc';
	}

	/**
	 * Serialise the variation matrix for the modal (axis selections → variation).
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array
	 */
	private function serialize_variations( $product ) {
		$rows = $product->get_available_variations();
		$out  = [];
		foreach ( $rows as $row ) {
			$variation_id = (int) $row['variation_id'];
			$v            = wc_get_product( $variation_id );
			if ( ! $v ) {
				continue;
			}
			$out[] = [
				'id'          => $variation_id,
				'attributes'  => $row['attributes'],
				'price_html'  => Template::price_html( $v ),
				'image'       => wp_get_attachment_image_url( $v->get_image_id(), 'medium' ),
				'is_in_stock' => (bool) $row['is_in_stock'],
				'is_purchasable' => (bool) $row['is_purchasable'],
				'sku'         => $v->get_sku(),
				'max_qty'     => $v->get_stock_quantity(),
			];
		}
		return $out;
	}

	/**
	 * Print the modal skeleton at end of body. JS hydrates it on demand.
	 */
	public function print_modal_template() {
		// Only emit once even if multiple widgets are on the page.
		if ( did_action( 'pag_modal_printed' ) ) {
			return;
		}
		do_action( 'pag_modal_printed' );

		Template::load_part( 'modal' );
	}
}
