# Product Archive Grid for Elementor

A premium Elementor widget for WooCommerce product archives. Renders a fully customisable, responsive product grid with discount/stock badges, Quick View modal (with variation swatches), custom Wishlist (with login merge), AJAX add-to-cart, and a Buy Now flow that preserves the existing cart. Built for WordPress + WooCommerce + Astra + Dokan.

> **Stack:** WordPress 6.0+ · PHP 7.4+ · WooCommerce 7.0+ · Elementor 3.5+

---

## Highlights

- **Single Elementor widget** with three control tabs: **Layout**, **Setting**, **Style**
- **Toggleable elements** (14 in total): featured image (full resolution, 1:1 frame), discount badge (% / amount / both), stock badge (in / out / backorder / low stock), Quick View, Wishlist, title, rating stars + numeric value, sold count, current price, old price (strikethrough subscript), Add to Cart, Vendor name (Dokan), image hover swap
- **Responsive layout** — desktop has Quick View top-right + Wishlist bottom-right; tablet/mobile hide Quick View and move Wishlist to top-right
- **Variable product price** — lowest sale price + that variation's regular price strikethrough inline
- **Add to Cart** — simple products use AJAX; variable products redirect to the product page
- **Quick View modal** — hero image + thumbnail strip, **variation swatches with auto-detected type** (color / image / label), live price + image update, accessible dialog with focus trap and ESC close
- **Buy Now** — snapshots the current cart (stacked, multi-item safe), redirects to checkout without clearing existing items, restores originals on `woocommerce_thankyou`, sendBeacon abandon ping
- **Custom Wishlist** — `user_meta` for logged-in, WC session + localStorage for guests, login-merge on `wp_login`, survives WC session expiry
- **Per-IP / per-user rate limiter** on every REST endpoint via WordPress transients
- **REST API** under `/wp-json/pag/v1/` with nonce + permission middleware
- **Astra theme bridge** + **Dokan vendor auto-scoping**
- **HPOS (custom order tables) compatible**
- **a11y / RTL / i18n** ready

---

## Install

### From source (this repo)

```bash
cd wp-content/plugins
git clone https://github.com/Aspeyash/Product-archive-grid.git product-archive-grid
```

Then activate in WP Admin → Plugins.

### From a release ZIP

Download the latest ZIP from [Releases](../../releases) (or build one locally with `zip -r product-archive-grid.zip .` from the repo root) and upload via WP Admin → **Plugins → Add New → Upload Plugin**.

---

## Usage

1. Activate WooCommerce + Elementor + this plugin.
2. Edit any page (or an Elementor archive/search template) with Elementor.
3. Search the widget panel for **Product Archive Grid** (under the "Product Archive Grid" or "WooCommerce Elements" categories).
4. Drop it on your layout. Configure the three tabs:
    - **Layout** — columns (responsive), gaps, source (All / Sale / Featured / Best sellers / Recent / By category / Manual / Current search-archive), orderby/order, per-page, include/exclude products, include/exclude categories, hide out-of-stock, pagination mode (Load More / Numbered / None)
    - **Setting** — toggle every element, switch icons (Wishlist, Wishlist active, Quick View, Add to Cart), discount badge format, image size, image hover swap, optional heading section
    - **Style** — full Elementor controls for heading, card, image, badges, title, rating/sold, price, action buttons, Load More

To use on the WooCommerce search archive, set **Source** to **Current search / archive** inside an Elementor archive/search template.

---

## Architecture

```
product-archive-grid.php          # plugin bootstrap + dependency checks + HPOS compat
uninstall.php                     # cleanup options/usermeta/transients on delete
readme.txt                        # WordPress.org plugin metadata
includes/
  class-plugin.php                # singleton bootstrap
  class-security.php              # nonce + IP + sanitise + kses-svg helpers
  class-rate-limiter.php          # per-IP/per-user transient-counter limiter
  class-rest-api.php              # /pag/v1/ router + middleware
  class-query.php                 # WP_Query builder (sanitised, shared with load-more)
  class-template.php              # discount/stock/price/icon helpers
  class-assets.php                # registration + bootstrap data
  class-compat-astra.php          # Astra design-token bridge
  class-compat-dokan.php          # vendor auto-scope + 'Sold by' helper
  class-quick-view.php            # /quick-view + swatch type detection
  class-buy-now.php               # /buy-now + thankyou restore (stacked snapshots)
  class-wishlist.php              # /wishlist + login merge
  widgets/class-product-grid-widget.php
templates/
  card.php, heading.php, empty.php, modal.php
  parts/badge-discount.php, badge-stock.php, price.php, rating.php,
        sold.php, add-to-cart.php, quick-view-button.php, wishlist-button.php
assets/
  css/widget.css, modal.css, editor.css
  js/widget.js, modal.js, buy-now.js, wishlist.js
```

---

## REST endpoints

All endpoints sit under `/wp-json/pag/v1/`. Each requires a valid nonce header (`X-WP-Nonce`) and is rate-limited per IP / per user.

| Method | Route | Purpose |
|---|---|---|
| `POST` | `/add-to-cart` | AJAX add for simple products. Variable returns a typed redirect error. |
| `GET`  | `/load-more` | Returns rendered HTML for the next page of results. |
| `GET`  | `/quick-view` | Product payload for the modal (gallery, attributes, variation matrix). |
| `POST` | `/buy-now` | Snapshot current cart, add buy-now product, return checkout URL. |
| `POST` | `/buy-now/abandon` | sendBeacon target — refreshes snapshot TTL. |
| `GET`  | `/wishlist` | Current wishlist + product previews. |
| `POST` | `/wishlist/toggle` | Add/remove a product. |
| `POST` | `/wishlist/clear` | Empty wishlist. |
| `POST` | `/wishlist/sync` | Push localStorage IDs into the WC session (guest case). |

---

## Filter hooks

- `pag_query_args( $args, $settings, $page )` — modify the WP_Query before products are fetched.
- `pag_rate_limits( $limits )` — tweak per-action `[max, window]` values.
- `pag_low_stock_threshold( $threshold, $product )` — change when the "Only N left" badge appears.

---

## Security notes

- Every REST endpoint goes through nonce verification + a permission callback + the rate limiter.
- All product IDs validated through `Security::get_validated_product()` (rejects invisible or non-existent products).
- Strict allow-list sanitisation on `source`, `orderby`, `order`, etc.
- Inline SVG output is `wp_kses`-filtered against an explicit tag/attribute allow-list.
- Catalog-hidden products are excluded from queries.
- Wishlist hard-capped at 200 items per visitor; Buy Now snapshot stack TTL of 24 hours.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
