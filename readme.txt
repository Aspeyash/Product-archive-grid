=== Product Archive Grid for Elementor ===
Contributors: yourname
Tags: woocommerce, elementor, product, grid, archive, dokan, astra, search
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
WC requires at least: 7.0
WC tested up to: 9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A premium Elementor widget for WooCommerce product archives. Drop it on any page or search/archive template; configure layout, settings, and full Elementor styling in three tabs.

== Description ==

A focused Elementor widget that renders a fully-customisable, responsive WooCommerce product grid. Built for WordPress + WooCommerce + Astra + Dokan.

= Card elements (each toggleable) =

* Featured image (full resolution, 1:1 frame)
* Discount badge (percentage, amount, or both — top-left)
* Stock badge (in/out/backorder/low stock — bottom-left)
* Quick View button (top-right, desktop only)
* Wishlist button (bottom-right desktop, top-right tablet/mobile)
* Title (clamped to 2 lines)
* Star rating + numeric value (hidden when no reviews)
* Sold count (from `total_sales`, completed orders only)
* Current price (bold) + old price (strikethrough subscript) inline
* Add to Cart button (rounded plus-cart icon)
* Vendor name (Dokan)

= Behaviour =

* Add to Cart: simple products → AJAX; variable → redirect to product page
* Variable price: shows the lowest variation sale price + that variation's regular price strikethrough
* Quick View, Buy Now, and custom Wishlist (PR-B / PR-C)
* Per-IP / per-user **rate limiting** on every REST endpoint via WP transients
* HPOS (custom order tables) compatible
* Astra theme bridge (consumes `--ast-global-color-*` variables)
* Dokan vendor auto-scoping inside store pages

= Three customisation tabs =

1. **Layout** — columns (responsive), gaps, source, orderby/order, per-page, include/exclude products, include/exclude categories, hide out-of-stock, pagination mode (Load More / Numbered / None)
2. **Setting** — toggle every element on/off, switch icons (wishlist / wishlist active / quick view / add to cart), discount badge format, image size, image hover swap, and the optional heading section above the grid
3. **Style** — full Elementor styling for heading, card, image, badges, title, rating/sold, price, action buttons, and Load More button

= Filter hooks =

* `pag_query_args` — modify the WP_Query before products are fetched
* `pag_rate_limits` — adjust per-action limits and windows
* `pag_low_stock_threshold` — change the threshold for the "low stock" badge

== Installation ==

1. Make sure WooCommerce and Elementor are installed and active.
2. Upload the `product-archive-grid` folder to `/wp-content/plugins/`.
3. Activate the plugin.
4. Edit any page or archive template with Elementor and search for **Product Archive Grid**.

== Changelog ==

= 1.0.0 =
* Initial release.

== Frequently Asked Questions ==

= Does this work on the WooCommerce search archive? =

Yes. Set the widget Source to **Current search / archive** when dropping it inside an Elementor archive/search template — it inherits the main query.

= Is it compatible with Dokan multi-vendor? =

Yes. On a Dokan store page the widget auto-scopes the query to that vendor. You can also enable **Vendor name** in the Setting tab to display "Sold by [vendor]".
