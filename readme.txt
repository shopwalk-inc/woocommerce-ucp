=== Shopwalk AI for WooCommerce ===
Contributors: shopwalkinc
Tags: woocommerce, ai, search, ecommerce, product-sync
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.10.1
WC tested up to: 10.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-enable your WooCommerce store in minutes. Let AI agents discover, browse, and buy from your store automatically.

== Description ==

**Shopwalk AI** makes your WooCommerce store visible and accessible to AI shopping agents. Activate the plugin and your store is instantly open to AI-powered discovery — no developer required, no license key needed.

This plugin is the **first and most complete Universal Commerce Protocol (UCP) server** implementation for WooCommerce. It exposes a full UCP-compliant REST API so any AI agent can autonomously browse your catalog, check stock, create checkout sessions, apply coupons, place orders, and track fulfillment — all without leaving your WooCommerce store.

= What It Does =

Modern shoppers increasingly use AI to find and buy products. Shopwalk AI bridges the gap between your WooCommerce store and the AI commerce layer, so your products surface in AI-driven search results and AI agents can complete purchases on behalf of shoppers.

= Features =

* **Automatic registration** — Activate the plugin and your store is registered with Shopwalk automatically. No license key entry required.
* **Automatic product sync** — Your entire catalog syncs to Shopwalk automatically. New products, price changes, and inventory updates propagate in real time.
* **AI discovery** — Your products surface in AI-powered searches. Shopwalk AI understands natural language and context to connect the right shoppers with your store.
* **AI browsing (Catalog API)** — AI agents can browse your full catalog via a structured REST API with filtering by category, price range, and stock status.
* **Product availability API** — Real-time stock and pricing endpoint for every product and variant, used by AI agents before adding to cart.
* **AI checkout** — AI agents create checkout sessions and place orders directly through your WooCommerce store using the Universal Commerce Protocol (UCP). No redirects, no middleman, no transaction fees.
* **Coupon / promotion code support** — AI agents can apply and remove WooCommerce coupon codes during checkout sessions.
* **Order status & tracking** — Full order status endpoint with UCP-standardized statuses and shipment tracking number support.
* **Refund API** — AI agents can initiate partial or full refunds programmatically.
* **Guest checkout assurance** — Shopwalk sessions always work as guest checkouts, even if your store has Require account enabled.
* **Session expiry** — Checkout sessions automatically expire after 24 hours for security.
* **Order webhooks** — Real-time order status notifications keep AI agents in sync with your fulfillment workflow.
* **UCP discovery** — `/.well-known/ucp` endpoint broadcasts your store's full UCP capabilities to any AI agent.
* **Merchant dashboard** — See products indexed, AI agent request count, UCP health, subscription status, and self-service tools directly in WP Admin.
* **Upgrade / downgrade in-place** — Manage your Shopwalk Pro subscription without leaving WP Admin.

= Getting Started =

1. Install and activate the plugin
2. Your store registers with Shopwalk automatically
3. Products begin syncing immediately
4. View your dashboard at **WooCommerce → Shopwalk**

= UCP Endpoints =

All endpoints are available at both `/wp-json/shopwalk/v1/` (UCP standard) and `/wp-json/shopwalk-wc/v1/` (legacy).

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/products` | GET | Public | Paginated catalog with filters |
| `/products/{id}` | GET | Public | Single product detail |
| `/products/{id}/availability` | GET | Public | Real-time stock & pricing |
| `/categories` | GET | Public | Product categories |
| `/checkout-sessions` | POST | Key | Create checkout session |
| `/checkout-sessions/{id}` | GET | Key | Get session state |
| `/checkout-sessions/{id}` | PUT | Key | Update session (buyer, address, coupons) |
| `/checkout-sessions/{id}/complete` | POST | Key | Place the order |
| `/checkout-sessions/{id}/cancel` | POST | Key | Cancel session |
| `/checkout-sessions/{id}/shipping-options` | GET | Key | Available shipping methods |
| `/orders/{id}` | GET | Key | Order status & tracking |
| `/orders/{id}/refund` | POST | Key | Issue refund |
| `/webhooks` | POST | Key | Register webhook URL |

== External Services ==

This plugin communicates with the **Shopwalk API** (https://api.shopwalk.com) for the following purposes:

**On activation:**
Sends your site URL, WordPress version, and WooCommerce version to register your store and obtain a merchant ID. No account creation or license key is required.
Data sent: `site_url`, `wp_version`, `wc_version`

**On product save / update:**
Sends product data to Shopwalk for AI indexing so your products appear in AI-powered search results.
Data sent: product name, description, price, SKU, stock status, images, categories.

**On order status change (webhooks):**
Sends order status updates to Shopwalk when orders placed via AI checkout change status (e.g. pending → processing → completed).
Data sent: order ID, UCP status, tracking number (if available). No customer personal data is included.

**Hourly (license refresh):**
Checks your current license level (Free or Pro) from the Shopwalk API using your site URL as the identifier. No personal data is sent.

**On plugin update checks:**
Periodically checks for plugin updates via the Shopwalk update API.
Data sent: current plugin version, site URL.

No customer personal data (names, addresses, payment information) is ever sent to Shopwalk. All payment processing and order management remains entirely within your WooCommerce store.

* Shopwalk Terms of Service: https://shopwalk.com/terms
* Shopwalk Privacy Policy: https://shopwalk.com/privacy

== Installation ==

1. Upload the `shopwalk-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Your store is registered automatically — no license key required
4. Go to **WooCommerce → Shopwalk** to view your dashboard

== Frequently Asked Questions ==

= Do I need to create an account or enter a license key? =

No. Activating the plugin registers your store with Shopwalk automatically. Your dashboard is available immediately at WooCommerce → Shopwalk.

= What products get synced? =

All published WooCommerce products including simple, variable, and grouped products. Draft and private products are not synced.

= How often does the catalog sync? =

Products sync in real time when created or updated. You can also trigger a full manual sync from the plugin dashboard.

= Does Shopwalk take a commission on sales? =

No. AI agents complete purchases through your own WooCommerce checkout. Shopwalk does not sit in the payment flow and does not charge transaction fees.

= What is the Universal Commerce Protocol (UCP)? =

UCP is an open protocol that lets AI agents interact with e-commerce stores in a structured way — browsing products, checking availability, creating checkout sessions, applying coupons, placing orders, and requesting refunds. This plugin implements a full UCP server on your WooCommerce store.

= Is my customer data shared with Shopwalk? =

No. Only product catalog data is sent to Shopwalk. Customer names, addresses, and payment information never leave your WooCommerce store. See the External Services section for full details.

= Does this work with WooCommerce High-Performance Order Storage (HPOS)? =

Yes. Shopwalk AI is fully compatible with WooCommerce HPOS (Custom Order Tables).

= Does this work with stores that require account registration? =

Yes. Shopwalk AI automatically bypasses the require account setting for AI-initiated orders, allowing guest checkout to always work.

= What happens if my site can't reach the Shopwalk API? =

The plugin degrades gracefully. Your WooCommerce store continues operating normally. Product sync and AI discovery will resume automatically when connectivity is restored. Run Diagnostics in the dashboard to troubleshoot.

= How do I upgrade to Shopwalk Pro? =

Click **Upgrade to Pro** in the Shopwalk dashboard (WooCommerce → Shopwalk). Pro adds an AI shopping assistant page, AI-powered chat widget, and AI content improvement tools. Learn more at https://shopwalk.com/pro

= Can I move my store to a new domain? =

Yes. Use the **I moved my site** tool in the Shopwalk dashboard to update your domain binding. Your catalog and subscription follow the new domain automatically.

== Screenshots ==

1. The Shopwalk dashboard in WP Admin — shows products indexed, AI agent requests, UCP health, and subscription status.
2. Self-service tools — upgrade, downgrade, cancel, migrate domain, and run diagnostics without contacting support.

== Changelog ==

= 1.10.0 =
* Feature: CDN origin fallback — images load immediately via Cloudflare Worker even before imgcache has processed them
* CDN URLs now include ?o= param (base64url-encoded original URL) for Worker origin fetch on R2 miss

= 1.9.0 =
* Feature: CDN image rewriting — product images served from cdn.shopwalk.com (enable in Settings > Advanced)
* Path scheme: merchants/{merchant_id}/md5({url}).{ext} — deterministic, zero API calls
* Hooks wp_get_attachment_url, wp_get_attachment_image_src, wp_calculate_image_srcset

= 1.8.0 =
* Safety: plugin catches fatal errors on boot, deactivates gracefully — wp-admin never goes down
* Feature: CDN image serving (feature-flagged off by default)
* Fix: Shopwalk_WC_Dashboard initialisation moved into Settings class
* Bump: version constants and stable tag aligned to 1.8.0

= 1.7.0 =
* NEW: Server-side license model — license level (Free/Pro) is checked from Shopwalk API using domain; no license key stored locally
* NEW: Hourly license refresh via WP-Cron — `shopwalk_license_refresh` event keeps license status current
* NEW: `shopwalk_is_pro()` helper function for use by Shopwalk Pro plugin
* NEW: Self-service dashboard — upgrade, downgrade, cancel, undo cancel, migrate domain, Stripe portal, and diagnostics from WP Admin
* NEW: Domain migration tool — updates merchant binding when moving to a new domain
* NEW: Diagnostics panel — checks PHP version, WC version, WP version, memory limit, API connectivity, UCP endpoint, license status, and merchant ID
* IMPROVED: Auto-registration is now idempotent — safe to call multiple times; re-registers if merchant was soft-deleted
* IMPROVED: Registration payload now includes `registration_token` when `SHOPWALK_REGISTRATION_TOKEN` is defined (used by Pro downloads)

= 1.6.0 =
* NEW: Merchant dashboard page in WP Admin (WooCommerce → Shopwalk)
* NEW: Dashboard shows products indexed, AI agent request count, UCP health status, and plugin version
* NEW: Dashboard data cached for 5 minutes to avoid unnecessary API calls
* NEW: API tracks `ucp_request_count` and `ucp_last_request_at` per merchant

= 1.5.0 =
* NEW: Auto-register store on plugin activation — no manual setup required
* NEW: Transient-based retry — if registration fails on activation, retries silently on next `admin_init`
* NEW: Deactivation hook notifies Shopwalk API so feeds pause immediately (non-blocking, fire-and-forget)
* IMPROVED: Merchant soft-delete on deactivation — data preserved, restored on re-activation

= 1.4.0 =
* FIX: Webhooks class uses WC hooks instead of REST routes — must be instantiated in `init_hooks()`
* FIX: Removed erroneous `register_routes()` call that caused fatal error on PHP 8.x
* FIX: Bulk sync rate-limited to 25 products per batch to avoid timeouts
* FIX: Double-delete prevented by `static $deleted_this_request` guard
* FIX: WP-Cron used for sync queue flush instead of inline execution
* FIX: 401 responses from UCP endpoint no longer added to retry queue

= 1.3.0 =
* Added UCP review submission endpoint — Shopwalk users can now post reviews to merchant stores

= 1.2.0 =
* Added product ratings and review count to sync payload
* Added UCP reviews endpoint for Shopwalk to fetch product reviews

= 1.1.0 =
* NEW: Product Availability endpoint (`GET /products/{id}/availability`) — real-time stock, pricing, and variant availability
* NEW: Catalog endpoint enhancements — added `min_price`, `max_price`, and `in_stock` filter params
* NEW: Coupon/discount code support in `update_session` — apply/remove WooCommerce coupons via `promotions` array
* NEW: Dedicated refund endpoint (`POST /orders/{id}/refund`) — supports partial and full refunds
* NEW: Order endpoint UCP status mapping — WC statuses mapped to UCP statuses
* NEW: UCP-standard `shopwalk/v1` namespace — all endpoints now available at `/wp-json/shopwalk/v1/`
* NEW: Enhanced `/.well-known/ucp` discovery document
* NEW: Guest checkout assurance — Shopwalk sessions bypass require account store setting
* NEW: Session expiry — sessions expire after 24 hours with `SESSION_EXPIRED` UCP error
* NEW: UCP standardized error codes

= 1.0.0 =
* Initial release
* Automatic product catalog sync
* Real-time inventory and price updates
* AI discovery integration
* AI browsing REST API
* AI checkout via Universal Commerce Protocol (UCP)
* Order webhook support
* HPOS compatibility

== Upgrade Notice ==

= 1.7.0 =
Major update: server-side license model and full self-service dashboard. No action required on upgrade — your merchant ID and product sync continue uninterrupted.

= 1.6.0 =
Adds merchant dashboard to WP Admin. No action required.

= 1.5.0 =
Plugin now auto-registers on activation. Existing installs are unaffected.

= 1.4.0 =
Bug fix release. Resolves fatal error on PHP 8.x and sync reliability issues. Upgrade recommended.
