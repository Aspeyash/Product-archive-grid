<?php
/**
 * Product Archive Grid — the Elementor widget.
 *
 * Three control tabs:
 *   1. Layout   (Content tab)
 *   2. Setting  (Content tab) — element toggles + icon swaps
 *   3. Style    (Style tab)   — full Elementor style controls
 *
 * Plus a heading section toggle + heading style controls.
 *
 * @package ProductArchiveGrid
 */

namespace PAG\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Elementor\Widget_Base;
use PAG\Query;
use PAG\Security;
use PAG\Template;

defined( 'ABSPATH' ) || exit;

/**
 * The widget.
 */
class Product_Grid_Widget extends Widget_Base {

	// Cache of taxonomy terms for the SELECT2 controls.
	private static $term_cache = [];

	public function get_name() {
		return 'pag_product_grid';
	}

	public function get_title() {
		return __( 'Product Archive Grid', 'product-archive-grid' );
	}

	public function get_icon() {
		return 'eicon-products';
	}

	public function get_categories() {
		return [ 'pag-woocommerce', 'woocommerce-elements' ];
	}

	public function get_keywords() {
		return [ 'product', 'grid', 'archive', 'woocommerce', 'shop', 'pag', 'dokan' ];
	}

	public function get_style_depends() {
		$deps = [ 'pag-widget' ];
		if ( wp_style_is( 'pag-modal', 'registered' ) ) {
			$deps[] = 'pag-modal';
		}
		return $deps;
	}

	public function get_script_depends() {
		$deps = [ 'pag-widget' ];
		foreach ( [ 'pag-modal', 'pag-buy-now', 'pag-wishlist' ] as $h ) {
			if ( wp_script_is( $h, 'registered' ) ) {
				$deps[] = $h;
			}
		}
		return $deps;
	}

	// =========================================================================
	// CONTROLS
	// =========================================================================

	protected function register_controls() {
		$this->register_layout_tab();
		$this->register_setting_tab();
		$this->register_style_tab();
	}

	// -------------------------------------------------------------------------
	// Tab 1: Layout
	// -------------------------------------------------------------------------
	private function register_layout_tab() {
		$this->start_controls_section(
			'sec_layout',
			[
				'label' => __( 'Layout', 'product-archive-grid' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_responsive_control(
			'columns',
			[
				'label'           => __( 'Columns per row', 'product-archive-grid' ),
				'type'            => Controls_Manager::SELECT,
				'default'         => '4',
				'tablet_default'  => '2',
				'mobile_default'  => '2',
				'options'         => [
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				],
				'selectors'       => [
					'{{WRAPPER}} .pag-grid' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
				],
			]
		);

		$this->add_responsive_control(
			'col_gap',
			[
				'label'      => __( 'Column gap', 'product-archive-grid' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'default'    => [ 'unit' => 'px', 'size' => 20 ],
				'selectors'  => [ '{{WRAPPER}} .pag-grid' => 'column-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'row_gap',
			[
				'label'      => __( 'Row gap', 'product-archive-grid' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'default'    => [ 'unit' => 'px', 'size' => 28 ],
				'selectors'  => [ '{{WRAPPER}} .pag-grid' => 'row-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_control(
			'source',
			[
				'label'   => __( 'Source', 'product-archive-grid' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'all',
				'options' => [
					'all'            => __( 'All products', 'product-archive-grid' ),
					'sale'           => __( 'On sale', 'product-archive-grid' ),
					'featured'       => __( 'Featured', 'product-archive-grid' ),
					'best_sellers'   => __( 'Best sellers', 'product-archive-grid' ),
					'recent'         => __( 'Recent', 'product-archive-grid' ),
					'by_category'    => __( 'By category', 'product-archive-grid' ),
					'manual'         => __( 'Manual selection', 'product-archive-grid' ),
					'current_search' => __( 'Current search / archive', 'product-archive-grid' ),
				],
			]
		);

		$this->add_control(
			'orderby',
			[
				'label'   => __( 'Order by', 'product-archive-grid' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date',
				'options' => [
					'date'       => __( 'Date', 'product-archive-grid' ),
					'title'      => __( 'Title', 'product-archive-grid' ),
					'price'      => __( 'Price', 'product-archive-grid' ),
					'popularity' => __( 'Popularity (sold count)', 'product-archive-grid' ),
					'rating'     => __( 'Rating', 'product-archive-grid' ),
					'menu_order' => __( 'Menu order', 'product-archive-grid' ),
					'rand'       => __( 'Random', 'product-archive-grid' ),
				],
				'condition' => [ 'source!' => 'current_search' ],
			]
		);

		$this->add_control(
			'order',
			[
				'label'   => __( 'Order', 'product-archive-grid' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'DESC',
				'options' => [
					'ASC'  => __( 'Ascending', 'product-archive-grid' ),
					'DESC' => __( 'Descending', 'product-archive-grid' ),
				],
				'condition' => [ 'source!' => 'current_search' ],
			]
		);

		$this->add_control(
			'per_page',
			[
				'label'   => __( 'Products per page', 'product-archive-grid' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 60,
				'default' => 12,
			]
		);

		$this->add_control(
			'include_ids',
			[
				'label'       => __( 'Include products (IDs)', 'product-archive-grid' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '12, 34, 56',
				'description' => __( 'Comma-separated product IDs.', 'product-archive-grid' ),
			]
		);

		$this->add_control(
			'exclude_ids',
			[
				'label'       => __( 'Exclude products (IDs)', 'product-archive-grid' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '78, 90',
				'description' => __( 'Comma-separated product IDs to exclude.', 'product-archive-grid' ),
			]
		);

		$this->add_control(
			'include_cats',
			[
				'label'       => __( 'Include categories', 'product-archive-grid' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->get_term_options( 'product_cat' ),
			]
		);

		$this->add_control(
			'exclude_cats',
			[
				'label'       => __( 'Exclude categories', 'product-archive-grid' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->get_term_options( 'product_cat' ),
			]
		);

		$this->add_control(
			'hide_oos',
			[
				'label'        => __( 'Hide out of stock', 'product-archive-grid' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$this->add_control(
			'pagination_mode',
			[
				'label'   => __( 'Pagination', 'product-archive-grid' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'load_more',
				'options' => [
					'none'      => __( 'None', 'product-archive-grid' ),
					'load_more' => __( 'Load More button', 'product-archive-grid' ),
					'numbered'  => __( 'Numbered links', 'product-archive-grid' ),
				],
			]
		);

		$this->add_control(
			'load_more_label',
			[
				'label'     => __( 'Load More label', 'product-archive-grid' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'See more', 'product-archive-grid' ),
				'condition' => [ 'pagination_mode' => 'load_more' ],
			]
		);

		// ---------------------------------------------------------------------
		// v1.1.0 — Algolia data-source toggle.
		//
		// The toggle is only registered when the ZYMARG Algolia Search plugin
		// is active (it exposes `zymarg_algolia_get_setting` as the bridge
		// function). When the plugin is missing we render a helpful notice
		// instead so users know how to unlock the feature.
		// ---------------------------------------------------------------------
		if ( function_exists( 'zymarg_algolia_get_setting' ) ) {
			$this->add_control(
				'use_algolia',
				[
					'label'        => __( 'Use Algolia for fast queries', 'product-archive-grid' ),
					'type'         => Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => '',
					'description'  => __(
						'Powers this grid via Algolia instead of WordPress queries. Requires the ZYMARG Algolia Search plugin to be active. Sub-100ms response times even on large catalogs.',
						'product-archive-grid'
					),
					'separator'    => 'before',
				]
			);
		} else {
			$this->add_control(
				'use_algolia_notice',
				[
					'type'      => Controls_Manager::RAW_HTML,
					'raw'       => '<div style="padding:10px;background:#fef3c7;border-left:3px solid #f59e0b;border-radius:3px;font-size:12px;line-height:1.5;color:#78350f;">'
						. esc_html__( 'Install ZYMARG Algolia Search to enable the Algolia data-source option (sub-100ms grids on large catalogs).', 'product-archive-grid' )
						. '</div>',
					'separator' => 'before',
				]
			);
		}

		$this->end_controls_section();
	}

	// -------------------------------------------------------------------------
	// Tab 2: Setting (element toggles + icon switches)
	// -------------------------------------------------------------------------
	private function register_setting_tab() {
		$this->start_controls_section(
			'sec_settings',
			[
				'label' => __( 'Setting', 'product-archive-grid' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		// Heading.
		$this->add_control(
			'show_heading',
			[
				'label'        => __( 'Show heading section', 'product-archive-grid' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$this->add_control(
			'heading_text',
			[
				'label'     => __( 'Heading text', 'product-archive-grid' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Featured products', 'product-archive-grid' ),
				'condition' => [ 'show_heading' => 'yes' ],
			]
		);

		$this->add_control(
			'heading_subtext',
			[
				'label'     => __( 'Sub-heading text', 'product-archive-grid' ),
				'type'      => Controls_Manager::TEXTAREA,
				'rows'      => 2,
				'condition' => [ 'show_heading' => 'yes' ],
			]
		);

		$this->add_control(
			'heading_tag',
			[
				'label'   => __( 'Heading HTML tag', 'product-archive-grid' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'h2',
				'options' => [
					'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4',
					'h5' => 'H5', 'h6' => 'H6', 'div' => 'div',
				],
				'condition' => [ 'show_heading' => 'yes' ],
			]
		);

		$this->add_control(
			'heading_align',
			[
				'label'   => __( 'Heading alignment', 'product-archive-grid' ),
				'type'    => Controls_Manager::CHOOSE,
				'options' => [
					'left'   => [ 'title' => __( 'Left', 'product-archive-grid' ),   'icon' => 'eicon-text-align-left' ],
					'center' => [ 'title' => __( 'Center', 'product-archive-grid' ), 'icon' => 'eicon-text-align-center' ],
					'right'  => [ 'title' => __( 'Right', 'product-archive-grid' ),  'icon' => 'eicon-text-align-right' ],
				],
				'default'   => 'left',
				'selectors' => [ '{{WRAPPER}} .pag-heading' => 'text-align: {{VALUE}};' ],
				'condition' => [ 'show_heading' => 'yes' ],
			]
		);

		// Element toggles.
		$this->add_control( 'div_elements', [ 'type' => Controls_Manager::DIVIDER ] );
		$this->add_control(
			'heading_elements',
			[
				'label' => __( 'Card elements', 'product-archive-grid' ),
				'type'  => Controls_Manager::HEADING,
			]
		);

		$toggles = [
			'show_image'          => [ __( 'Featured image', 'product-archive-grid' ),         'yes' ],
			'show_discount_badge' => [ __( 'Discount badge', 'product-archive-grid' ),         'yes' ],
			'show_stock_badge'    => [ __( 'Stock badge', 'product-archive-grid' ),            'yes' ],
			'show_quick_view'     => [ __( 'Quick View button (desktop)', 'product-archive-grid' ), 'yes' ],
			'show_wishlist'       => [ __( 'Wishlist button', 'product-archive-grid' ),        'yes' ],
			'show_title'          => [ __( 'Product title', 'product-archive-grid' ),          'yes' ],
			'show_rating'         => [ __( 'Rating stars', 'product-archive-grid' ),           'yes' ],
			'show_rating_value'   => [ __( 'Rating numeric value', 'product-archive-grid' ),   'yes' ],
			'show_sold'           => [ __( 'Sold count', 'product-archive-grid' ),             'yes' ],
			'show_price'          => [ __( 'Current price', 'product-archive-grid' ),          'yes' ],
			'show_old_price'      => [ __( 'Old price (strikethrough)', 'product-archive-grid' ), 'yes' ],
			'show_add_to_cart'    => [ __( 'Add to Cart button', 'product-archive-grid' ),     'yes' ],
			'show_vendor'         => [ __( 'Vendor name (Dokan)', 'product-archive-grid' ),    '' ],
			'image_hover_swap'    => [ __( 'Image hover swap (gallery)', 'product-archive-grid' ), '' ],
		];

		foreach ( $toggles as $key => [ $label, $default ] ) {
			$this->add_control(
				$key,
				[
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => $default,
				]
			);
		}

		$this->add_control( 'div_format', [ 'type' => Controls_Manager::DIVIDER ] );

		$this->add_control(
			'discount_format',
			[
				'label'     => __( 'Discount badge format', 'product-archive-grid' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'percent',
				'options'   => [
					'percent' => __( 'Percentage (-25%)', 'product-archive-grid' ),
					'amount'  => __( 'Amount saved (-$10)', 'product-archive-grid' ),
					'both'    => __( 'Both (-25% / Save $10)', 'product-archive-grid' ),
				],
				'condition' => [ 'show_discount_badge' => 'yes' ],
			]
		);

		$this->add_control(
			'image_size',
			[
				'label'   => __( 'Featured image size', 'product-archive-grid' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'full',
				'options' => $this->get_image_size_options(),
				'description' => __( 'Default Full per spec; CSS forces a 1:1 aspect ratio.', 'product-archive-grid' ),
				'condition'   => [ 'show_image' => 'yes' ],
			]
		);

		$this->add_control( 'div_icons', [ 'type' => Controls_Manager::DIVIDER ] );
		$this->add_control(
			'heading_icons',
			[
				'label' => __( 'Icons', 'product-archive-grid' ),
				'type'  => Controls_Manager::HEADING,
			]
		);

		$this->add_control(
			'icon_wishlist',
			[
				'label'     => __( 'Wishlist icon', 'product-archive-grid' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => [ 'value' => '', 'library' => '' ],
				'recommended' => [
					'fa-solid' => [ 'heart' ],
					'fa-regular' => [ 'heart' ],
				],
				'condition' => [ 'show_wishlist' => 'yes' ],
			]
		);

		$this->add_control(
			'icon_wishlist_active',
			[
				'label'     => __( 'Wishlist success icon', 'product-archive-grid' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => [ 'value' => '', 'library' => '' ],
				'condition' => [ 'show_wishlist' => 'yes' ],
			]
		);

		$this->add_control(
			'icon_quick_view',
			[
				'label'     => __( 'Quick View icon', 'product-archive-grid' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => [ 'value' => '', 'library' => '' ],
				'condition' => [ 'show_quick_view' => 'yes' ],
			]
		);

		$this->add_control(
			'icon_add_to_cart',
			[
				'label'     => __( 'Add to Cart icon', 'product-archive-grid' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => [ 'value' => '', 'library' => '' ],
				'condition' => [ 'show_add_to_cart' => 'yes' ],
			]
		);

		$this->end_controls_section();
	}

	// -------------------------------------------------------------------------
	// Tab 3: Style — full Elementor control surface.
	// -------------------------------------------------------------------------
	private function register_style_tab() {
		$this->register_style_heading();
		$this->register_style_card();
		$this->register_style_image();
		$this->register_style_badges();
		$this->register_style_title();
		$this->register_style_rating_sold();
		$this->register_style_price();
		$this->register_style_buttons();
		$this->register_style_load_more();
	}

	private function register_style_heading() {
		$this->start_controls_section(
			'style_heading',
			[
				'label'     => __( 'Heading', 'product-archive-grid' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_heading' => 'yes' ],
			]
		);

		$this->add_control(
			'heading_color',
			[
				'label'     => __( 'Title color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .pag-heading__title' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'heading_typography',
				'selector' => '{{WRAPPER}} .pag-heading__title',
			]
		);

		$this->add_control(
			'subheading_color',
			[
				'label'     => __( 'Sub-heading color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .pag-heading__sub' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'subheading_typography',
				'selector' => '{{WRAPPER}} .pag-heading__sub',
			]
		);

		$this->add_responsive_control(
			'heading_spacing',
			[
				'label'      => __( 'Spacing below heading', 'product-archive-grid' ),
				'type'       => Controls_Manager::SLIDER,
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 100 ] ],
				'default'    => [ 'unit' => 'px', 'size' => 24 ],
				'selectors'  => [ '{{WRAPPER}} .pag-heading' => 'margin-bottom: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();
	}

	private function register_style_card() {
		$this->start_controls_section(
			'style_card',
			[
				'label' => __( 'Card', 'product-archive-grid' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'card_bg',
			[
				'label'     => __( 'Background', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => [ '{{WRAPPER}} .pag-card' => 'background-color: {{VALUE}};' ],
			]
		);

		$this->add_responsive_control(
			'card_padding',
			[
				'label'      => __( 'Padding', 'product-archive-grid' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .pag-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'card_radius',
			[
				'label'      => __( 'Border radius', 'product-archive-grid' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'default'    => [ 'top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12, 'unit' => 'px', 'isLinked' => true ],
				'selectors'  => [
					'{{WRAPPER}} .pag-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[ 'name' => 'card_border', 'selector' => '{{WRAPPER}} .pag-card' ]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[ 'name' => 'card_shadow', 'selector' => '{{WRAPPER}} .pag-card' ]
		);

		$this->end_controls_section();
	}

	private function register_style_image() {
		$this->start_controls_section(
			'style_image',
			[
				'label'     => __( 'Image', 'product-archive-grid' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_image' => 'yes' ],
			]
		);

		$this->add_responsive_control(
			'image_radius',
			[
				'label'      => __( 'Image border radius', 'product-archive-grid' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .pag-card__image, {{WRAPPER}} .pag-card__image img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_style_badges() {
		$this->start_controls_section(
			'style_badges',
			[
				'label' => __( 'Badges', 'product-archive-grid' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control( 'h_discount', [ 'label' => __( 'Discount badge', 'product-archive-grid' ), 'type' => Controls_Manager::HEADING ] );

		$this->add_control(
			'discount_bg',
			[
				'label'     => __( 'Background', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dc2626',
				'selectors' => [ '{{WRAPPER}} .pag-badge--discount' => 'background-color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'discount_color',
			[
				'label'     => __( 'Text color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => [ '{{WRAPPER}} .pag-badge--discount' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_control( 'h_stock', [ 'label' => __( 'Stock badge', 'product-archive-grid' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ] );

		$this->add_control(
			'stock_in_color',
			[
				'label'     => __( 'In-stock color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#16a34a',
				'selectors' => [ '{{WRAPPER}} .pag-badge--stock.is-instock' => 'background-color: {{VALUE}}; color:#fff;' ],
			]
		);
		$this->add_control(
			'stock_out_color',
			[
				'label'     => __( 'Out-of-stock color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#6b7280',
				'selectors' => [ '{{WRAPPER}} .pag-badge--stock.is-outofstock' => 'background-color: {{VALUE}}; color:#fff;' ],
			]
		);

		$this->end_controls_section();
	}

	private function register_style_title() {
		$this->start_controls_section(
			'style_title',
			[
				'label'     => __( 'Title', 'product-archive-grid' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_title' => 'yes' ],
			]
		);

		$this->add_control(
			'title_color',
			[
				'label'     => __( 'Color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .pag-card__title, {{WRAPPER}} .pag-card__title a' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'title_hover_color',
			[
				'label'     => __( 'Hover color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .pag-card__title a:hover' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[ 'name' => 'title_typography', 'selector' => '{{WRAPPER}} .pag-card__title' ]
		);

		$this->end_controls_section();
	}

	private function register_style_rating_sold() {
		$this->start_controls_section(
			'style_rating',
			[
				'label' => __( 'Rating & sold', 'product-archive-grid' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'rating_filled_color',
			[
				'label'     => __( 'Star filled color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#f59e0b',
				'selectors' => [ '{{WRAPPER}} .pag-rating__star.is-filled' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'rating_empty_color',
			[
				'label'     => __( 'Star empty color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#d1d5db',
				'selectors' => [ '{{WRAPPER}} .pag-rating__star' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'rating_value_color',
			[
				'label'     => __( 'Rating value color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .pag-rating__value, {{WRAPPER}} .pag-sold' => 'color: {{VALUE}};' ],
			]
		);

		$this->end_controls_section();
	}

	private function register_style_price() {
		$this->start_controls_section(
			'style_price',
			[
				'label'     => __( 'Price', 'product-archive-grid' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_price' => 'yes' ],
			]
		);
		$this->add_control(
			'price_current_color',
			[
				'label'     => __( 'Current price color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .pag-price__current' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'price_old_color',
			[
				'label'     => __( 'Old price color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#9ca3af',
				'selectors' => [ '{{WRAPPER}} .pag-price__old' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[ 'name' => 'price_typography', 'selector' => '{{WRAPPER}} .pag-price__current' ]
		);
		$this->end_controls_section();
	}

	private function register_style_buttons() {
		$this->start_controls_section(
			'style_buttons',
			[
				'label' => __( 'Action buttons', 'product-archive-grid' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control( 'h_atc', [ 'label' => __( 'Add to Cart', 'product-archive-grid' ), 'type' => Controls_Manager::HEADING ] );
		$this->add_control(
			'atc_bg',
			[
				'label'     => __( 'Background', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#111111',
				'selectors' => [ '{{WRAPPER}} .pag-card__atc' => 'background-color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'atc_color',
			[
				'label'     => __( 'Icon color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => [ '{{WRAPPER}} .pag-card__atc' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'atc_bg_hover',
			[
				'label'     => __( 'Background on hover', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .pag-card__atc:hover' => 'background-color: {{VALUE}};' ],
			]
		);

		$this->add_control( 'h_overlay', [ 'label' => __( 'Quick View / Wishlist', 'product-archive-grid' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ] );
		$this->add_control(
			'overlay_bg',
			[
				'label'     => __( 'Overlay button background', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(255,255,255,0.95)',
				'selectors' => [ '{{WRAPPER}} .pag-card__icon-btn' => 'background-color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'overlay_color',
			[
				'label'     => __( 'Overlay icon color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#111111',
				'selectors' => [ '{{WRAPPER}} .pag-card__icon-btn' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'wishlist_active_color',
			[
				'label'     => __( 'Wishlist active color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dc2626',
				'selectors' => [ '{{WRAPPER}} .pag-card__icon-btn.is-active' => 'color: {{VALUE}};' ],
			]
		);

		$this->end_controls_section();
	}

	private function register_style_load_more() {
		$this->start_controls_section(
			'style_load_more',
			[
				'label'     => __( 'Load More', 'product-archive-grid' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'pagination_mode' => 'load_more' ],
			]
		);
		$this->add_control(
			'lm_bg',
			[
				'label'     => __( 'Background', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#111111',
				'selectors' => [ '{{WRAPPER}} .pag-load-more' => 'background-color: {{VALUE}};' ],
			]
		);
		$this->add_control(
			'lm_color',
			[
				'label'     => __( 'Color', 'product-archive-grid' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => [ '{{WRAPPER}} .pag-load-more' => 'color: {{VALUE}};' ],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[ 'name' => 'lm_typo', 'selector' => '{{WRAPPER}} .pag-load-more' ]
		);
		$this->end_controls_section();
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Memoised list of taxonomy term options.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string,string>
	 */
	private function get_term_options( $taxonomy ) {
		if ( isset( self::$term_cache[ $taxonomy ] ) ) {
			return self::$term_cache[ $taxonomy ];
		}
		$options = [];
		if ( taxonomy_exists( $taxonomy ) ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 200,
				]
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$options[ $t->slug ] = $t->name;
				}
			}
		}
		self::$term_cache[ $taxonomy ] = $options;
		return $options;
	}

	/**
	 * Image size options.
	 *
	 * @return array<string,string>
	 */
	private function get_image_size_options() {
		$out = [ 'full' => __( 'Full', 'product-archive-grid' ) ];
		if ( function_exists( 'get_intermediate_image_sizes' ) ) {
			foreach ( get_intermediate_image_sizes() as $s ) {
				$out[ $s ] = $s;
			}
		}
		return $out;
	}

	// =========================================================================
	// RENDER
	// =========================================================================

	protected function render() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$raw      = $this->get_settings_for_display();
		$settings = $this->normalize_settings( $raw );

		// Use raw text for heading bits since they aren't query-related.
		$settings['heading_text']    = isset( $raw['heading_text'] ) ? wp_kses_post( $raw['heading_text'] ) : '';
		$settings['heading_subtext'] = isset( $raw['heading_subtext'] ) ? wp_kses_post( $raw['heading_subtext'] ) : '';
		$settings['heading_tag']     = in_array( $raw['heading_tag'] ?? 'h2', [ 'h1','h2','h3','h4','h5','h6','div' ], true ) ? $raw['heading_tag'] : 'h2';
		$settings['show_heading']    = ( ( $raw['show_heading'] ?? '' ) === 'yes' );
		$settings['load_more_label'] = isset( $raw['load_more_label'] ) && '' !== $raw['load_more_label']
			? sanitize_text_field( $raw['load_more_label'] )
			: __( 'See more', 'product-archive-grid' );
		$settings['pagination_mode'] = in_array( $raw['pagination_mode'] ?? 'load_more', [ 'none', 'load_more', 'numbered' ], true )
			? $raw['pagination_mode']
			: 'load_more';

		// Custom icon overrides — capture rendered HTML for the four switchable icons.
		$settings['icon_html'] = [
			'wishlist'        => $this->render_elementor_icon( $raw['icon_wishlist'] ?? null, 'heart' ),
			'wishlist_active' => $this->render_elementor_icon( $raw['icon_wishlist_active'] ?? null, 'heart-filled' ),
			'quick_view'      => $this->render_elementor_icon( $raw['icon_quick_view'] ?? null, 'eye' ),
			'add_to_cart'     => $this->render_elementor_icon( $raw['icon_add_to_cart'] ?? null, 'cart-plus' ),
		];

		$query = Query::build( $settings, 1 );

		$wrapper_classes = [ 'pag-grid-wrapper' ];

		printf(
			'<div class="%s" data-pag-settings="%s">',
			esc_attr( implode( ' ', $wrapper_classes ) ),
			esc_attr( wp_json_encode( $this->client_safe_settings( $settings ) ) )
		);

		Template::render_heading( $settings );

		if ( $query->have_posts() ) {
			echo '<div class="pag-grid" role="list">';
			while ( $query->have_posts() ) {
				$query->the_post();
				Template::render_card( get_the_ID(), $settings );
			}
			echo '</div>';

			$this->render_pagination( $query, $settings );
		} else {
			Template::render_empty();
		}

		wp_reset_postdata();

		echo '</div>';
	}

	/**
	 * Sanitise the raw widget settings before passing into Query::build.
	 *
	 * @param array $raw Settings.
	 * @return array
	 */
	private function normalize_settings( array $raw ) {
		$bool_yes = static fn( $k ) => ( ( $raw[ $k ] ?? '' ) === 'yes' );

		$user_source = $raw['source'] ?? 'all';
		$use_algolia = $bool_yes( 'use_algolia' ) && function_exists( 'zymarg_algolia_get_setting' );

		$settings = [
			// When Algolia is opted in, route through the new data source but
			// preserve the user's selected mode so Algolia_Query knows which
			// filter to apply.
			'source'        => $use_algolia ? 'algolia' : $user_source,
			'algolia_mode'  => $use_algolia ? $user_source : '',
			'orderby'       => $raw['orderby'] ?? 'date',
			'order'         => $raw['order']   ?? 'DESC',
			'per_page'      => isset( $raw['per_page'] ) ? (int) $raw['per_page'] : 12,
			'include_ids'   => $raw['include_ids']  ?? '',
			'exclude_ids'   => $raw['exclude_ids']  ?? '',
			'include_cats'  => $raw['include_cats'] ?? [],
			'exclude_cats'  => $raw['exclude_cats'] ?? [],
			'hide_oos'      => $bool_yes( 'hide_oos' ),
			'image_size'    => $raw['image_size'] ?? 'full',
			'discount_format' => $raw['discount_format'] ?? 'percent',
		];

		// Toggle keys (booleans).
		foreach ( [
			'show_image', 'show_discount_badge', 'show_stock_badge', 'show_quick_view',
			'show_wishlist', 'show_title', 'show_rating', 'show_rating_value', 'show_sold',
			'show_price', 'show_old_price', 'show_add_to_cart', 'show_vendor', 'image_hover_swap',
		] as $k ) {
			$settings[ $k ] = $bool_yes( $k );
		}

		return Query::sanitize_settings( $settings );
	}

	/**
	 * Subset of settings that's safe to embed in the DOM for the load-more
	 * REST call. Strips toggles + display-only fields the server doesn't trust.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private function client_safe_settings( array $settings ) {
		$keys = [
			'source', 'orderby', 'order', 'per_page',
			'include_ids', 'exclude_ids', 'include_cats', 'exclude_cats',
			'hide_oos', 'search_term', 'vendor_id',
			'algolia_mode',
			'show_image', 'show_discount_badge', 'show_stock_badge',
			'show_quick_view', 'show_wishlist', 'show_title', 'show_rating',
			'show_rating_value', 'show_sold', 'show_price', 'show_old_price',
			'show_add_to_cart', 'show_vendor', 'image_hover_swap',
			'image_size', 'discount_format',
		];
		$out = [];
		foreach ( $keys as $k ) {
			if ( isset( $settings[ $k ] ) ) {
				$out[ $k ] = $settings[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Render a chosen Elementor icon control or fall back to our SVG.
	 *
	 * @param mixed  $icon_setting Elementor icon array.
	 * @param string $fallback     SVG name from Template::icon().
	 * @return string Safe HTML.
	 */
	private function render_elementor_icon( $icon_setting, $fallback ) {
		if ( is_array( $icon_setting ) && ! empty( $icon_setting['value'] ) ) {
			ob_start();
			\Elementor\Icons_Manager::render_icon( $icon_setting, [ 'aria-hidden' => 'true' ] );
			$html = ob_get_clean();
			if ( $html ) {
				return $html;
			}
		}
		return Template::icon( $fallback );
	}

	/**
	 * Render Load More / numbered pagination.
	 *
	 * @param \WP_Query $query    Query.
	 * @param array     $settings Settings (already client-safe).
	 */
	private function render_pagination( $query, array $settings ) {
		$mode = $settings['pagination_mode'] ?? 'load_more';
		if ( 'none' === $mode ) {
			return;
		}

		$total = (int) $query->max_num_pages;
		if ( $total < 2 ) {
			return;
		}

		if ( 'numbered' === $mode ) {
			$big   = 999999999;
			$paged = max( 1, get_query_var( 'paged' ), get_query_var( 'page' ) );
			$links = paginate_links(
				[
					'base'    => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
					'format'  => '?paged=%#%',
					'current' => $paged,
					'total'   => $total,
					'type'    => 'array',
				]
			);
			if ( ! empty( $links ) ) {
				echo '<nav class="pag-pagination" aria-label="' . esc_attr__( 'Product pagination', 'product-archive-grid' ) . '"><ul>';
				foreach ( $links as $l ) {
					echo '<li>' . wp_kses_post( $l ) . '</li>';
				}
				echo '</ul></nav>';
			}
			return;
		}

		// load_more.
		$label = $settings['load_more_label'] ?? __( 'See more', 'product-archive-grid' );
		printf(
			'<div class="pag-load-more-wrap"><button type="button" class="pag-load-more" data-next-page="2" data-total-pages="%d">%s</button></div>',
			esc_attr( $total ),
			esc_html( $label )
		);
	}
}
