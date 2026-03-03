=== Shopwalk AI ===
Contributors: shopwalkinc
Tags: ai shopping, product sync, woocommerce, ai commerce, ai checkout, ucp
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-enable your WooCommerce store in minutes. Let AI agents discover, browse, and buy from your store automatically.

== Description ==

**Shopwalk AI** makes your WooCommerce store visible and accessible to AI shopping agents. Install the plugin, enter your license key, and your store is instantly open to AI-powered discovery, browsing, and checkout — no developer required.

This plugin is the **first and most complete Universal Commerce Protocol (UCP) server** implementation for WooCommerce. It exposes a full UCP-compliant REST API so any AI agent can autonomously browse your catalog, check stock, create checkout sessions, apply coupons, place orders, and track fulfillment — all without leaving your WooCommerce store.

= What It Does =

Modern shoppers increasingly use AI to find and buy products. Shopwalk AI bridges the gap between your WooCommerce store and the AI commerce layer, so your products surface in AI-driven search results and AI agents can complete purchases on behalf of shoppers.

= Features =

* **Automatic product sync** — Your entire catalog syncs to Shopwalk automatically. New products, price changes, and inventory updates propagate in real time.
* **AI discovery** — Your products surface in AI-powered searches. Shopwalk AI understands natural language and context to connect the right shoppers with your store.
* **AI browsing (Catalog API)** — AI agents can browse your full catalog via a structured REST API with filtering by category, price range, and stock status.
* **Product availability API** — Real-time stock and pricing endpoint for every product and variant, used by AI agents before adding to cart.
* **AI checkout** — AI agents create checkout sessions and place orders directly through your WooCommerce store using the Universal Commerce Protocol (UCP). No redirects, no middleman, no transaction fees.
* **Coupon / promotion code support** — AI agents can apply and remove WooCommerce coupon codes during checkout sessions.
* **Order status & tracking** — Full order status endpoint with UCP-standardized statuses and shipment tracking number support.
* **Refund API** — AI agents can initiate partial or full refunds programmatically.
* **Guest checkout assurance** — Shopwalk sessions always work as guest checkouts, even if your store has "Require account" enabled.
* **Session expiry** — Checkout sessions automatically expire after 24 hours for security.
* **Order webhooks** — Real-time order status notifications keep AI agents in sync with your fulfillment workflow.
* **UCP discovery** — `/.well-known/ucp` endpoint broadcasts your store's full UCP capabilities to any AI agent.
* **Simple setup** — Connect in minutes with your Shopwalk license key.

= Getting Started =

1. Install and activate the plugin
2. Go to **WooCommerce → Settings → Shopwalk**
3. Enter your Shopwalk license key (purchase at [shopwalk.com](https://shopwalk.com))
4. Your products will begin syncing immediately

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

= Privacy & External Services =

This plugin communicates with the Shopwalk API (`api.shopwalk.com`) for the following purposes:

* **Product sync** — sends product data (name, description, price, images, inventory status) to Shopwalk for AI indexing when products are saved or updated.
* **License activation** — sends your site URL and license key to Shopwalk to validate your license.
* **Update checks** — periodically checks for plugin updates via the Shopwalk update API.
* **Order webhooks** — sends order status data to Shopwalk when orders placed via AI checkout change status.

No customer personal data (names, addresses, payment info) is ever sent to Shopwalk. All payment processing and order management remains entirely within your WooCommerce store.

By using this plugin, you agree to the [Shopwalk Terms of Service](https://shopwalk.com/terms) and [Privacy Policy](https://shopwalk.com/privacy).

== Installation ==

1. Upload the `shopwalk-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Go to **WooCommerce → Settings → Shopwalk** and enter your license key
4. Your products will start syncing to Shopwalk automatically

== Frequently Asked Questions ==

= How do I get a license key? =

Purchase a license key at [shopwalk.com](https://shopwalk.com). Each license covers one WooCommerce store.

= What products get synced? =

All published WooCommerce products including simple, variable, and grouped products. Draft and private products are not synced.

= How often does the catalog sync? =

Products sync in real time when created or updated. You can also trigger a full manual sync from the plugin settings page.

= Does Shopwalk take a commission on sales? =

No. AI agents complete purchases through your own WooCommerce checkout. Shopwalk does not sit in the payment flow and does not charge transaction fees.

= What is the Universal Commerce Protocol (UCP)? =

UCP is an open protocol that lets AI agents interact with e-commerce stores in a structured way — browsing products, checking availability, creating checkout sessions, applying coupons, placing orders, and requesting refunds. This plugin implements a full UCP server on your WooCommerce store.

= How do AI agents browse my catalog? =

Via `GET /wp-json/shopwalk/v1/products` with optional filters:
- `?search=keyword` — full-text search
- `?category=slug` — filter by category slug
- `?min_price=10&max_price=100` — price range (in store currency)
- `?in_stock=true` — only in-stock items
- `?page=2&per_page=50` — pagination (max 100 per page)

= How do AI agents check if a product is in stock? =

Via `GET /wp-json/shopwalk/v1/products/{id}/availability`. Returns real-time stock status, quantity, pricing in cents, and individual variant availability for variable products.

= How do coupon codes work with AI checkout? =

In the `PUT /checkout-sessions/{id}` request, include:
```json
{ "promotions": [{ "code": "SAVE10" }] }
```
Pass an empty array `"promotions": []` to remove all applied coupons.

= How long do checkout sessions last? =

Sessions expire after 24 hours. Attempting to use an expired session returns a `SESSION_EXPIRED` error. Start a new session via `POST /checkout-sessions`.

= Does this work with stores that require account registration? =

Yes. Shopwalk AI automatically bypasses the "require account" setting for AI-initiated orders, allowing guest checkout to always work. If needed, a bot email is assigned so the order passes WooCommerce validation.

= Can AI agents request refunds? =

Yes. Via `POST /wp-json/shopwalk/v1/orders/{id}/refund` with body `{ "reason": "...", "amount_cents": 1000 }`. Omit `amount_cents` for a full refund. The plugin creates a WooCommerce refund record; actual payment reversal is handled by your payment gateway.

= What UCP statuses does the order API return? =

WooCommerce statuses are mapped to UCP statuses:
- `pending` / `on-hold` → `pending`
- `processing` → `confirmed`
- `completed` → `fulfilled`
- `cancelled` / `failed` → `cancelled`
- `refunded` → `refunded`

= What is the /.well-known/ucp endpoint? =

It's a machine-readable discovery document (JSON) that broadcasts your store's UCP capabilities to AI agents. Access it at `https://yourstore.com/.well-known/ucp`. It lists all endpoint URLs, supported capabilities, payment methods, and store metadata.

= Is my customer data shared with Shopwalk? =

No. Only product catalog data is sent to Shopwalk. Customer names, addresses, and payment information never leave your WooCommerce store.

= Does this work with WooCommerce High-Performance Order Storage (HPOS)? =

Yes. Shopwalk AI is fully compatible with WooCommerce HPOS (Custom Order Tables).

== Changelog ==

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
* NEW: Order endpoint UCP status mapping — WC statuses mapped to UCP statuses (pending/confirmed/fulfilled/cancelled/refunded)
* NEW: Order endpoint now returns shipping address, tracking number/URL, per-item tax totals, and discount total
* NEW: UCP-standard `shopwalk/v1` namespace — all endpoints now available at `/wp-json/shopwalk/v1/` (UCP) and `/wp-json/shopwalk-wc/v1/` (legacy)
* NEW: Enhanced `/.well-known/ucp` discovery — full UCP document with capabilities, endpoints, currency, store name
* NEW: Guest checkout assurance — Shopwalk sessions bypass "require account" store setting
* NEW: Session expiry — sessions expire after 24 hours with `SESSION_EXPIRED` UCP error
* NEW: UCP standardized error codes — `OUT_OF_STOCK`, `INVALID_COUPON`, `PAYMENT_FAILED`, `SESSION_NOT_FOUND`, `SESSION_EXPIRED`, `INVALID_ADDRESS`
* NEW: Applied promotions (coupon codes) reflected in session response
* NEW: `price_cents` (integer, UCP-standard) added to all product and order responses
* NEW: `X-UCP-Version: 1.0` response header on all Shopwalk endpoints
* Updated: Plugin version bumped to 1.1.0

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

= 1.3.0 =
Adds POST /shopwalk/v1/reviews so Shopwalk users can submit reviews directly to your store (held for moderation).

= 1.2.0 =
Adds product ratings/review count to the sync payload and a new authenticated reviews endpoint.

= 1.1.0 =
Major UCP compliance update. Adds availability endpoint, coupon support, refund API, session expiry, standardized error codes, and dual-namespace routing. No breaking changes to existing `shopwalk-wc/v1` endpoints.

= 1.0.0 =
Initial release.
