# Beltoft Commerce

One WooCommerce install, two shops: a public **retail** site and a **trade** (B2B) site for resellers, on separate hostnames. Trade partners get their own prices, their own catalogue and EU reverse-charge VAT; retail visitors never see any of it.

## What it does

- **Retail hosts and trade hosts.** Logged-out visitors to a trade host get the login and application pages only. Trade pages redirect off the retail hosts, generated URLs stay on the host that was asked, and no trade host is ever page-cached or indexed. Optionally one trade host per language, each serving its language's trade pages at its own paths (see *One trade host per language*).
- **Pricing groups.** Each trade group has stored prices per product (`_price_group_<group>`, sale price and dates alongside). A product with no price for the visitor's group is not purchasable — never priced at retail. Groups come from the `pricing_groups` filter; today `b2b_standard`. Group prices are quoted on the trade host only: a signed-in partner on any other host is priced, shown the catalogue and charged as a retail shopper, with a cart of its own. Staff acting for a customer in wp-admin or the REST API, cron and WP-CLI still price for the customer's group.
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

### One trade host per language (optional)

With a language plugin (Zerolang) the trade site can have one host per language. Without `BELTOFT_B2B_HOSTS` there is one trade host and nothing below applies.

```php
define( 'BELTOFT_B2B_HOST', 'b2b.example.pt' );          // the default language's trade host
define( 'BELTOFT_B2B_HOSTS', array(
	'pt' => 'b2b.example.pt',                            // must equal BELTOFT_B2B_HOST
	'en' => 'b2b.example.com',
) );
define( 'BELTOFT_COMMERCE_CONFIG', array(
	'trade_fallback_language' => 'en',                   // languages without a trade host use this one's
	'trade_paths' => array(
		'en' => array( 'login_path' => '/sign-in/', 'register_path' => '/apply/', 'account_path' => '/account/',
		               'quick_order_path' => '/quick-order/', 'checkout_path' => '/checkout/',
		               'lost_password_paths' => array( '/account/lost-password/' ) ),
	),
) );
```

- Every host in the map is a trade host in everything: gated, never cached or indexed, priced for the partner's group, its own cart, its orders trade orders. A logged-out visitor is sent to that host's own login path; another language's login path is not public there.
- Each language needs its own copies of the trade pages (Zerolang translations). A copy of a root page is served at its slug without `b2b-` (`b2b-shop` at `/shop/`); a language without a copy gets a 404 there, never the default language's page. The checkout and account page ids are filtered at priority 5, so the language plugin translates them.
- The early host handler passes the map to Zerolang as its `trade` host set (`zerolang_host_sets`), so links to another language on a trade host go to that language's trade host. `COOKIE_DOMAIN` must stay undefined.
- Trade-only paths asked on the site address go to the default trade host; on a language domain (or under a language prefix) to that language's trade host, at `plugins_loaded:2`.
- A partner's own trade host is recorded at application and at sign-in on a trade host (user meta `_beltoft_trade_host`); application emails are sent in that host's language, and back-in-stock links and orders with no recorded host use it.
- Tools > Site Health checks the configuration, the pages behind `trade_paths` and the root pages' copies, and fetches each host's `robots.txt` and `/`; administrators see configuration problems as a notice. Removing `BELTOFT_B2B_HOSTS` returns to one trade host.
- In a path-mode language setup (`/en/`, `/es/`), `path_language_prefixes` (segment => code) tells the early gate the prefixes; later code reads them from the language plugin.

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
| `trade_paths` | `[]` | Per language code, any path key above for that language's trade host (`'en' => array( 'login_path' => '/sign-in/' )`); a missing key is the base value. |
| `trade_fallback_language` | `en` | The trade host of a language that has none of its own. |
| `path_language_prefixes` | `[]` | Path mode only: URL segment => language code, for the early gate. |
| `lost_password_paths` | WooCommerce's endpoint under `/a-minha-conta/`, `/conta/`, `/conta-b2b/` | Reachable logged out. Change with `woocommerce_myaccount_lost_password_endpoint`. |
| `account_path`, `account_legacy_paths` | `/conta/`, `['/conta-b2b/']` | Trade account area, and old addresses forwarded to it. |
| `quick_order_path` | `/encomenda-rapida/` | Quick order page. |
| `checkout_path`, `checkout_legacy_paths` | `/finalizar/`, `['/finalizar-b2b/']` | Trade checkout and old addresses. |
| `trade_page_prefix` | `/b2b-` | Physical slug prefix of trade copies of shared pages. |
| `ordering_page` | `loja` | Root-pages key of the ordering list. |
| `header_template`, `footer_template` | `b2b-cabecalho`, `b2b-rodape` | Bricks template slugs for the trade host. |
| `home_country` | `PT` | VAT numbers and deliveries here are domestic. |
| `unload_on_trade` | mindthetrash's list | Plugin folders not loaded on trade page views. |
| `extra_public_paths`, `extra_trade_only_paths` | `[]` | More paths open to logged-out trade visitors (prefixes), and more paths that belong to the trade host. |
| `retail_robots` | mindthetrash's rules | Retail robots.txt lines, or `null` to leave robots.txt alone. |

More logged-out paths and more trade-only paths go in the `extra_public_paths` and `extra_trade_only_paths` settings, the trade plugin list in `unload_on_trade`. The matching filters (`beltoft_commerce_b2b_public_paths`, `beltoft_commerce_b2b_only_paths`, `beltoft_commerce_b2b_active_plugins`) run in the early handler, before plugins and themes load, so a callback has effect only from an mu-plugin that loads before `beltoft-commerce-host.php`.

## Hooks for other code

- `pricing_groups` (filter) — the groups: `slug => array( 'label', 'roles' => [...], 'prices' => 'own'|'inherit', 'trade' => bool )`.
- `beltoft_commerce_group_price_changed` (action, `$product_id, $parent_id`) — a group price or sale window changed.
- `beltoft_commerce_b2b_applied` / `_approved` / `_rejected` (actions, `$user_id`).
- `beltoft_commerce_feed_product_ids` (filter) — run a product feed's ids through it; it keeps what the public (retail) shop shows, whoever builds the feed.
- `beltoft_commerce_rows_cache` (filter, bool) — switch off the ordering-list result cache.
- `beltoft_commerce_b2b_hosts` (filter) — the trade host map, code => host (after `BELTOFT_B2B_HOSTS`).
- `beltoft_commerce_language_*` (filters: `current`, `default`, `codes`, `of_post`, `siblings`, `url_in`, `retail_host`, `is_domain`, `separate_domains`, `path_prefixes`, `of_order`) and `beltoft_commerce_default_language_post` — what `Support\Language` asks the language plugin, for another language plugin or for tests.
- Bricks: dynamic tags `{beltoft_rrp}`, `{beltoft_ean}`, `{beltoft_summary}`, `{beltoft_buy}`, `{beltoft_cart_shipping}`, `{beltoft_cart_product_id}`; element condition **Host (retail / B2B)** (`beltoft_host`).
- beltoft-scanner: registers the `b2b-loja` context and answers `beltoft_scanner_assess` / `beltoft_scanner_notify` (the latter only with beltoft-in-stock-notifier).

Staff (wp-admin, the management REST APIs, WP-CLI outside cron) see the whole catalogue. Internal queries that must too run inside `Enforcement\QueryFilter::without_audience( fn() => ... )`.

## Development

No build step. Tests are `WP_UnitTestCase` under `tests/`, mirroring `src/`; the harness and its throwaway database are set up outside this repo (`WP_TESTS_DIR`, `BELTOFT_SITE_PLUGINS` in `tests/bootstrap.php`). `tests/Exposure/` is the contract that trade prices and trade-only products never reach a retail visitor. Strings are English with a complete `pt_PT` translation in `languages/` (an `es_ES` file exists but covers only a few strings).
