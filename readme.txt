=== Product Archive Grid for Elementor ===
Contributors: zymarg
Tags: woocommerce, elementor, product, grid, archive, dokan, astra, search
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
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

= 1.1.0 =
* **New: Optional Algolia data source.** Each grid widget now has a "Use Algolia for fast queries" toggle (Layout tab). When enabled the grid is powered by Algolia instead of `WP_Query`, with sub-100ms response times even on very large catalogs. Default behaviour is unchanged — widgets without the toggle render byte-identically to v1.0.1.
* **Single source of truth for credentials.** PAG reads Algolia App ID, Search-Only API Key, and index name from the sibling **ZYMARG Algolia Search** plugin via `zymarg_algolia_get_setting()` / `zymarg_algolia_index_name()`. PAG itself does not add a settings page or duplicate any credentials. Requires ZYMARG Algolia Search v1.0.18+ for the new product fields.
* **The toggle only appears when ZYMARG Algolia Search is active.** When the bridge plugin is missing, the widget shows a small in-editor notice telling the user how to enable the option. If a widget was previously saved with the toggle on and the bridge plugin is later deactivated, the widget silently falls back to `WP_Query` instead of erroring.
* **All existing source modes work via Algolia:** All products, On sale, Featured, Best sellers, Recent, By category, Manual selection, Current search / archive. Auto-detects vendor scope on Dokan store pages.
* **Multi-host failover** built in: every Algolia request tries `<app>-dsn.algolia.net` first, then `-1.algolianet.com`, `-2`, `-3`. Bounded 8s timeout via `wp_remote_post()`. Optional 5-minute transient cache, controllable with `apply_filters( 'pag_algolia_query_cache', false )`.
* **Backwards compatible.** No new settings page. No new admin UI other than the single toggle. No external dependencies (no Composer, no npm, no CDN). All existing card markup, classnames, hooks, and widget controls are preserved.
* New file: `includes/class-algolia-query.php` — extends `WP_Query` so the widget loop and the load-more REST endpoint both work transparently.
* New file: `includes/class-card-data.php` — exposes `pag_card_data()`, a normaliser that returns the same array shape regardless of whether the underlying source is a `WC_Product` or an Algolia hit. Card sub-templates now read from `$data` so the same templates render both paths identically.
* New filters: `pag_algolia_query_cache` (bool, default true), `pag_algolia_query_cache_ttl` (seconds, default 300), `pag_algolia_request_body` (array — modify the Algolia search body before it's sent).
* **New: GitHub auto-updater.** Plugin now appears in WP admin → Updates page like any other plugin. Click "Check for updates" on the Plugins screen to pull new releases from GitHub Releases automatically. Same mechanism as `zymarg-algolia-search` — adds `pre_set_site_transient_update_plugins` + `plugins_api` filters and a "Check for updates" row-meta link, with a 6-hour transient cache for GitHub API responses. New file: `includes/class-pag-updater.php`. Bootstrapped admin/cron-only from `class-plugin.php`.
* **Fix: Add to Cart and Buy Now buttons** previously failed in some environments with "WooCommerce not active" because WC's cart object isn't auto-initialised in REST API requests (only on front-end page loads via `wc_init_frontend_default_hooks()`). PAG's `/add-to-cart` and `/buy-now` REST handlers now defensively call `wc_load_cart()` to ensure the cart is ready before adding products. The WC-not-active and cart-unavailable failure modes are split into distinct error codes (`pag_no_wc` vs `pag_cart_unavailable`) for clearer diagnostics.

= 1.0.1 =
* Fix: Card sub-templates (discount badge, stock badge, Quick View button, Wishlist button, rating, sold count, price, Add to Cart) were rendering as empty wrappers because the template loader called `sanitize_file_name()` on names that contained a forward slash (e.g. `parts/price`). WordPress strips slashes from filenames, so the resolved path no longer existed and the include silently failed. The loader now uses an explicit allow-list regex plus a `realpath()` containment check.

= 1.0.0 =
* Initial release.

== Frequently Asked Questions ==

= Does this work on the WooCommerce search archive? =

Yes. Set the widget Source to **Current search / archive** when dropping it inside an Elementor archive/search template — it inherits the main query.

= Is it compatible with Dokan multi-vendor? =

Yes. On a Dokan store page the widget auto-scopes the query to that vendor. You can also enable **Vendor name** in the Setting tab to display "Sold by [vendor]".
