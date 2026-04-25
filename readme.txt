=== WooCommerce UCP — Universal Commerce Protocol ===
Contributors: shopwalkinc
Tags: woocommerce, ai, ucp, agent, commerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
WC requires at least: 8.0
WC tested up to: 9.8
Stable tag: 3.0.44
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make any WooCommerce store fully purchasable by AI shopping agents. Free, standalone, no account required.

== Description ==

This plugin makes any WooCommerce store **fully purchasable by AI shopping agents** that speak the [Universal Commerce Protocol (UCP)](https://ucp.dev). Install it and your store can be discovered, browsed, and transacted by any UCP-compliant client — with **no account, no signup, no external service required**.

Optional Shopwalk network integration is available for merchants who want real-time push sync to the Shopwalk network and Premier listing on shopwalk.com.

= What you get out of the box (no account needed) =

* **Full UCP REST surface** under `/wp-json/ucp/v1/`
  * `oauth/authorize`, `oauth/token`, `oauth/revoke`, `oauth/userinfo` — OAuth 2.0 server for buyer identity
  * `checkout-sessions` — full session lifecycle (create, update, complete, cancel)
  * `orders`, `orders/{id}`, `orders/{id}/events` — order retrieval for the OAuth-authenticated buyer
  * `webhooks/subscriptions` — outbound order event subscriptions
* **OAuth 2.0 server** with authorization_code + refresh_token grants, bound to WordPress user accounts (which are also WooCommerce customers)
* **Outbound webhook delivery** with HMAC signing, exponential backoff retry, and dead-letter on permanent failure
* **WC payment gateway "Pay via UCP"** registered automatically
* **Discovery doc** at `/.well-known/ucp` and OAuth metadata at `/.well-known/oauth-authorization-server` (RFC 8414) — both served via static PHP shims so they work on Apache shared hosts that rewrite the URI before WordPress sees it
* **Self-test diagnostic** runner with one-click checks from the dashboard
* **WordPress + WooCommerce native** — uses dbDelta, WP-Cron, and standard hooks. No custom infrastructure.

= With an optional Shopwalk account =

* Real-time push sync of products to the Shopwalk network
* Premier placement on shopwalk.com
* Analytics dashboard at shopwalk.com/partners
* Faster index updates than the UCP pull path alone provides

When the Shopwalk integration is **not** connected, the plugin makes **zero outbound HTTP calls** to Shopwalk. Tier 1 (UCP) and Tier 2 (Shopwalk integration) are strictly separated.

= Standards compliance =

This plugin implements the UCP spec from [ucp.dev](https://ucp.dev) exactly. It does not invent its own protocol. Any UCP-compliant agent (today: Shopwalk; tomorrow: any other agent built on the open standard) can transact against this plugin out of the box.

== Installation ==

1. Upload the `woocommerce-ucp` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Search for "WooCommerce UCP"**
2. Activate the plugin
3. Visit **UCP** in the WP Admin sidebar
4. Click **"Run self-test"** to verify your environment supports the UCP layer
5. (Optional) Click **"Connect to Shopwalk"** if you also want to sync to the Shopwalk network

== Frequently Asked Questions ==

= Does the plugin work without a Shopwalk account? =

Yes. The entire UCP REST surface (OAuth, checkout, orders, webhooks) works immediately after activation with no account, no signup, and no outbound HTTP traffic to Shopwalk. Any UCP-compliant agent on the public internet can transact against your store.

= What is UCP? =

UCP (Universal Commerce Protocol) is an open standard that lets AI agents discover, browse, transact, and receive order updates from any store that implements it. The spec is at [ucp.dev](https://ucp.dev). This plugin is the WooCommerce-side implementation.

= Does this work on Bluehost or shared hosting? =

Yes. The discovery doc is served via a static `.well-known/ucp.php` shim with an `.htaccess` rewrite, which works on Apache shared hosts that rewrite the URI before WordPress sees it. The webhook delivery worker uses WP-Cron, which works on every WordPress install.

= What data does the plugin send to Shopwalk? =

**Nothing — until you opt in.** The Tier 1 UCP layer is completely standalone and makes zero outbound HTTP calls to Shopwalk. If you click "Connect to Shopwalk" and enter a license key, the Tier 2 module activates and starts pushing product updates to api.shopwalk.com over an authenticated HTTPS channel.

= What WooCommerce version is required? =

WooCommerce 8.0 or later. WordPress 6.0 or later. PHP 8.0 or later.

= Does the plugin have its own payment keys? =

No. The plugin reuses whatever payment gateway you already have configured in WooCommerce — WC Stripe, WC PayPal, Square, Amazon Pay, anything on the WooCommerce marketplace. When a UCP agent completes a checkout session, the plugin dispatches the payment through an adapter that reads your existing gateway's credentials; you never enter payment keys in the plugin itself.

= What happens during an AI agent purchase? =

The agent creates a UCP checkout session, submits a tokenized payment credential, and calls `/complete`. The plugin routes that credential to your existing WooCommerce gateway (e.g. WC Stripe), authorizes the payment, and creates the WooCommerce order in the usual `processing` state — identical to a native checkout. If the agent can't auto-authorize (for example when 3D Secure is required), the session falls back to returning a `payment_url` the agent can hand to the buyer.

= Which payment gateways are supported out of the box? =

Stripe (via the WooCommerce Stripe Gateway plugin). Additional adapters for PayPal, Square, and others can be added via the `shopwalk_ucp_payment_adapters` filter without modifying plugin code. The WP Admin **UCP → Payments** panel shows which adapters are registered, which are ready, and deep-links into WooCommerce for any that aren't configured yet.

= How do I uninstall cleanly? =

Deactivating the plugin stops the WP-Cron jobs and removes the static `.well-known` files. Deleting the plugin (via WP Admin → Plugins → Delete) drops every `wp_ucp_*` table, deletes every `shopwalk_*` WP option, and removes all scheduled crons. Your WooCommerce data is untouched.

== External Services ==

When the optional Shopwalk integration is connected (a license key is entered in the dashboard), this plugin sends product data to Shopwalk's servers at `api.shopwalk.com` over authenticated HTTPS:

* Product names, descriptions, short descriptions, and SKUs
* Product prices and regular/sale prices
* Product image URLs (images themselves are not uploaded)
* Product availability and stock quantity
* Product categories
* Product page URLs

This data is used to index the store on the Shopwalk shopping network. **No data is sent to Shopwalk when the plugin is installed without an active license.**

Shopwalk Terms of Service: https://shopwalk.com/terms
Shopwalk Privacy Policy: https://shopwalk.com/privacy

== Changelog ==

= 3.0.0 =
**Complete rewrite as a UCP-compliant adapter.** The plugin's primary identity is now "the UCP adapter for WooCommerce." Shopwalk integration is one of several features layered on top.

* New file structure: `includes/{core,shopwalk,admin}/` with strict tier separation
* Namespace migration: all routes moved from `/wp-json/shopwalk/v1/` to `/wp-json/ucp/v1/`
* OAuth 2.0 server added (authorize, token, revoke, userinfo) with bcrypt-hashed token storage
* Order endpoints added (`/orders`, `/orders/{id}`, `/orders/{id}/events`)
* Outbound webhook delivery system added (subscriptions, queue, WP-Cron worker, HMAC signing, exponential backoff retry)
* WooCommerce payment gateway "Pay via UCP" registered automatically
* Discovery doc at `/.well-known/ucp` + OAuth metadata at `/.well-known/oauth-authorization-server`
* WP Admin dashboard rebuilt with two-section layout (UCP status + Shopwalk CTA/status)
* Self-test diagnostic with 8 automated checks
* Catalog endpoints removed — `shopwalk-feeds` reads `/wp-json/wc/v3/products` (standard WooCommerce REST API) directly, no plugin required
* Database: 5 new `wp_ucp_*` tables (oauth_clients, oauth_tokens, checkout_sessions, webhook_subscriptions, webhook_queue)

= 2.0.0 =
* Two-state plugin: free (UCP only) and connected (Shopwalk network)
* Free state: zero API calls, zero data sent to Shopwalk

== Upgrade Notice ==

= 3.0.0 =
Complete rewrite. The plugin is now a vendor-neutral UCP adapter. All routes have moved from `/wp-json/shopwalk/v1/` to `/wp-json/ucp/v1/`. If you were calling the old endpoints directly, update your client.
