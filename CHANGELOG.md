# Changelog

All notable changes to Shopwalk AI are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versions follow [Semantic Versioning](https://semver.org/).

---

## [3.0.0] — 2026-04-09

Complete rewrite as a UCP-compliant adapter. The plugin's primary identity is now "the UCP adapter for WooCommerce." Shopwalk integration is one of several optional features layered on top.

### Architecture
- New file structure: `includes/{core,shopwalk,admin}/` with strict tier separation. `core/` files MUST NOT import from `shopwalk/`. Removing the entire `shopwalk/` directory leaves the plugin functional as a pure UCP adapter — that's the litmus test for tier separation.
- Tier 1 (UCP core) is mandatory and standalone. Tier 2 (Shopwalk integration) is optional, gated by a license key entered in the dashboard CTA.

### Added — Tier 1 (UCP core)
- **OAuth 2.0 server** — `/wp-json/ucp/v1/oauth/{authorize,token,revoke,userinfo}`. Authorization_code + refresh_token grants. Tokens stored bcrypt-hashed in `wp_ucp_oauth_tokens`. WordPress users are the buyer identity (also the WC customer).
- **Checkout sessions** — `/wp-json/ucp/v1/checkout-sessions` with full UCP lifecycle (create / get / update / complete / cancel). Sessions live in `wp_ucp_checkout_sessions` with a 30-minute TTL enforced by an hourly cleanup cron.
- **Order endpoints** — `GET /orders`, `GET /orders/{id}`, `GET /orders/{id}/events`. Filtered by the OAuth-authenticated buyer's customer ID. Read directly from WC via `wc_get_orders` and mapped to the UCP Order Object shape.
- **Outbound webhooks** — `POST/GET/DELETE /webhooks/subscriptions`. WC order status changes are captured into a queue table; a WP-Cron worker (`shopwalk_ucp_webhook_flush`) flushes pending events every minute, signs the payload with the per-subscription HMAC secret, POSTs to the agent's callback URL, and retries with exponential backoff on 5xx.
- **WooCommerce payment gateway "Pay via UCP"** registered automatically. UCP-driven orders flow through the standard WC order pipeline so reports, refunds, and admin UI all work normally.
- **Discovery doc** at `/.well-known/ucp` (JSON capabilities document listing every endpoint) and **OAuth server metadata** at `/.well-known/oauth-authorization-server` (RFC 8414). Both served via static PHP shims with an `.htaccess` rewrite so they work on Apache shared hosts that rewrite the URI before WordPress sees it.
- **WP Admin dashboard** under "Shopwalk AI" in the WP sidebar with a two-section layout: Tier 1 UCP status + Tier 2 Shopwalk CTA / connected-state panel.
- **Self-test runner** with 8 automated checks (well-known reachability, OAuth wired, checkout endpoint wired, WP-Cron alive, WC payment gateway registered, signing secret present, all 5 DB tables exist).
- **Database schema** — 5 new tables created on activation via dbDelta():
  - `wp_ucp_oauth_clients`
  - `wp_ucp_oauth_tokens`
  - `wp_ucp_checkout_sessions`
  - `wp_ucp_webhook_subscriptions`
  - `wp_ucp_webhook_queue`
- **Store signing keypair** generated on first activation, persisted in WP options.

### Changed
- **Namespace migration** — all routes moved from `/wp-json/shopwalk/v1/` to `/wp-json/ucp/v1/`. The plugin now fully complies with the UCP spec endpoint paths.
- **Plugin display name** updated to "Shopwalk AI — UCP Commerce Adapter for WooCommerce" to reflect the new dual identity.
- **`uninstall.php`** rewritten to drop the new `wp_ucp_*` tables, clear the new cron jobs, and remove both `.well-known` files.

### Removed
- **Catalog endpoints** — `/products`, `/products/{id}`, `/store`, `/categories`, `/availability`. UCP doesn't include catalog discovery in its spec; `shopwalk-feeds` reads `/wp-json/wc/v3/products` (standard WooCommerce REST API) directly, no plugin required for catalog indexing.
- **All v2.x classes** — `class-shopwalk-wc-{products,ucp,sync,settings,dashboard}.php` and `class-shopwalk-wc.php` deleted entirely. The new architecture has no migration path because v2.x had no live data.

---

## [1.13.0] — 2026-03-09

### Changed
- Free plugin streamlined to core features only: UCP discovery + Store Boost (R2 image CDN)
- Removed semantic search overlay, AI description assistant, and search intelligence teaser from free tier

### Removed
- `class-shopwalk-wc-search.php` — semantic search overlay (Pro feature)
- `class-shopwalk-wc-ai-assist.php` — AI product description improvement (Pro feature)
- `class-shopwalk-wc-search-gaps.php` — search gap intelligence (Pro feature)
- `assets/js/shopwalk-search.js`, `assets/css/shopwalk-search.css`

---

## [1.1.0] — 2026-02-25

### Added
- Full UCP v1.1.0 spec implementation
- Coupon / promotion code support in checkout sessions
- Product availability endpoint (`/availability`) — real-time stock and pricing per variant
- Catalog filters: `min_price`, `max_price`, `in_stock` on products endpoint
- Order status and tracking endpoint with UCP-standardized status codes
- Dedicated refund endpoint (partial and full refunds)
- Guest checkout assurance — sessions always work as guest even if store requires accounts
- Session expiry — checkout sessions auto-expire after 24 hours
- Auto-registration — one-click store connection with auto-provisioned free API key
- Connect screen shown on first activation (before any key is set)
- Backward-compatible key migration (from `shopwalk_wc_license_key` / `shopwalk_wc_shopwalk_api_key`)
- `/.well-known/ucp` dual-namespace discovery endpoint (`/shopwalk/v1` and `/v1`)
- `X-UCP-Version: 1.0` response header on all UCP endpoints
- AI Commerce Status dashboard in plugin settings
- Test Connection and Sync Products Now buttons in settings

### Changed
- Plugin renamed to **Shopwalk AI** (slug: `shopwalk-ai`)
- Unified all key options under `shopwalk_wc_plugin_key`
- API key header changed from `Authorization: Bearer` to `X-API-Key` on ingest calls

### Security
- Pre-submission audit: nonce validation on all AJAX handlers, `sanitize_text_field` on all inputs, `esc_html` / `esc_attr` on all outputs, `gmdate()` instead of `date()`

---

## [1.0.0] — 2025-12-01

### Added
- Initial release
- Basic product sync (create, update, delete)
- UCP checkout sessions (create, fill, complete, cancel)
- Order webhooks (order status notifications to Shopwalk)
- Plugin settings tab in WooCommerce admin
- Uninstall cleanup (`uninstall.php`)
- HPOS compatibility declaration
