# Beltoft Commerce

One WooCommerce install, two shops: a public **retail** site and a **trade** (B2B) site for resellers, on separate hostnames. Trade partners get their own prices, their own catalogue and EU reverse-charge VAT; retail visitors never see any of it.

## What it does

- **Two hosts.** Logged-out visitors to the trade host get the login and application pages only. Trade pages redirect off the retail host, generated URLs stay on the host that was asked, and the trade host is never page-cached or indexed.
- **Pricing groups.** Each trade group has stored prices per product (`_price_group_<group>`, sale price and dates alongside). A product with no price for the visitor's group is not purchasable — never priced at retail. Groups come from the `pricing_groups` filter; today `b2b_standard`.
- **Catalogue audience.** Products are retail-only (no term), trade-only or both (`product_audience` taxonomy). Listings, search, related products, upsells, the Store API, REST, oEmbed and feeds all follow it.
- **Partner applications.** Application form → `b2b_pending`; staff approve (→ `b2b_customer`) or reject from the Users screen; three WooCommerce emails.
- **VAT.** A VAT number checked against VIES, from another EU country than the shop's, with the goods leaving the shop's country, is zero-rated (reverse charge). The VIES evidence is stored on the order; unverified orders are flagged on the orders screen.
- **Trade ordering.** Ordering list with quantity steppers and variation rows, quick order from a spreadsheet (`[beltoft_quick_order]`), saved carts (`[beltoft_saved_carts]`), reorder, PO numbers, trade payment and shipping methods, a fiscal-data account page.

## Requirements

PHP 8.3+, WooCommerce (required, declared in the plugin header). Built for Bricks on the front end (dynamic tags, conditions, header/footer templates); optional integrations with beltoft-scanner, beltoft-in-stock-notifier and Relevanssi, each used only if present.

## Install

1. In `wp-config.php`:
   ```php
   define( 'BELTOFT_B2B_HOST', 'b2b.example.com' );   // required; no trade site without it
   define( 'BELTOFT_RETAIL_HOST', 'example.com' );    // optional; defaults to the home URL's host
   ```
2. Deploy the plugin folder and activate it. Activation:
   - copies `mu-plugin/beltoft-commerce-host.php` into `wp-content/mu-plugins/` (the early host handler; kept up to date on admin requests, removed on deactivation — edit the copy in this repo, never the installed one);
   - creates the `b2b_pending` / `b2b_customer` roles and the price lookup table, and queues the table's first fill.
3. Create the trade pages and point the plugin at them (ids are per install):
   ```bash
   wp option update beltoft_commerce_b2b_root_pages '{"loja":ID,"finalizar":ID,"termos-condicoes":ID}' --format=json
   wp option update beltoft_commerce_b2b_checkout_page_id <ID>
   wp option update beltoft_commerce_b2b_account_page_id <ID>
   ```
   The root-pages map serves a trade copy of a page at the same path on the trade host (`/loja/` → the page whose slug is `b2b-loja`). Plus the login and application pages at `login_path` / `register_path`, and Bricks templates named by `header_template` / `footer_template`.
4. Optional options: `beltoft_commerce_b2b_shipping_instances` (shipping instance ids offered to trade), `beltoft_commerce_b2b_email_logo` (attachment id), `beltoft_commerce_own_vat_number`.

## Configuration

Site-specific settings live in `src/Support/Config.php`. The defaults are mindthetrash's; override what differs with a constant (read before plugins load) or, for code that runs later, the `beltoft_commerce_config` filter:

```php
define( 'BELTOFT_COMMERCE_CONFIG', array(
	'login_path'      => '/login/',
	'register_path'   => '/apply/',
	'home_country'    => 'ES',
	'retail_robots'   => null,
) );
```

| Key | Default | Meaning |
|---|---|---|
| `login_path`, `register_path` | `/entrar/`, `/registo/` | The trade host's public pages. |
| `lost_password_paths` | WooCommerce's endpoint under `/a-minha-conta/`, `/conta/`, `/conta-b2b/` | Reachable logged out. Change with `woocommerce_myaccount_lost_password_endpoint`. |
| `account_path`, `account_legacy_paths` | `/conta/`, `['/conta-b2b/']` | Trade account area, and old addresses forwarded to it. |
| `quick_order_path` | `/encomenda-rapida/` | Quick order page. |
| `checkout_path`, `checkout_legacy_paths` | `/finalizar/`, `['/finalizar-b2b/']` | Trade checkout and old addresses. |
| `trade_page_prefix` | `/b2b-` | Physical slug prefix of trade copies of shared pages. |
| `ordering_page` | `loja` | Root-pages key of the ordering list. |
| `header_template`, `footer_template` | `b2b-cabecalho`, `b2b-rodape` | Bricks template slugs for the trade host. |
| `home_country` | `PT` | VAT numbers and deliveries here are domestic. |
| `unload_on_trade` | mindthetrash's list | Plugin folders not loaded on trade page views. |
| `retail_robots` | mindthetrash's rules | Retail robots.txt lines, or `null` to leave robots.txt alone. |

Trade-only paths and paths reachable logged out can also be extended with `beltoft_commerce_b2b_only_paths` and `beltoft_commerce_b2b_public_paths`; the trade plugin list with `beltoft_commerce_b2b_active_plugins`.

## Hooks for other code

- `pricing_groups` (filter) — the groups: `slug => array( 'label', 'roles' => [...], 'prices' => 'own'|'inherit', 'trade' => bool )`.
- `beltoft_commerce_group_price_changed` (action, `$product_id, $parent_id`) — a group price or sale window changed.
- `beltoft_commerce_b2b_applied` / `_approved` / `_rejected` (actions, `$user_id`).
- `beltoft_commerce_feed_product_ids` (filter) — run a product feed's ids through it to apply the audience.
- `beltoft_commerce_rows_cache` (filter, bool) — switch off the ordering-list result cache.
- Bricks: dynamic tags `{beltoft_rrp}`, `{beltoft_ean}`, `{beltoft_summary}`, `{beltoft_buy}`, `{beltoft_cart_shipping}`, `{beltoft_cart_product_id}`; element condition **Host (retail / B2B)** (`beltoft_host`).
- beltoft-scanner: registers the `b2b-loja` context and answers `beltoft_scanner_assess` / `beltoft_scanner_notify` (the latter only with beltoft-in-stock-notifier).

Staff (wp-admin, the management REST APIs, WP-CLI outside cron) see the whole catalogue. Internal queries that must too run inside `Enforcement\QueryFilter::without_audience( fn() => ... )`.

## Development

No build step. Tests are `WP_UnitTestCase` under `tests/`, mirroring `src/`; the harness and its throwaway database are set up outside this repo (`WP_TESTS_DIR`, `BELTOFT_SITE_PLUGINS` in `tests/bootstrap.php`). `tests/Exposure/` is the contract that trade prices and trade-only products never reach a retail visitor. Strings are English with `pt_PT` and `es_ES` translations in `languages/`.
