=== Shopwalk ===
Contributors: shopwalkinc
Tags: woocommerce, ai, ucp, shopping, commerce, ai-shopping
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WooCommerce store discoverable by AI shopping agents. Free UCP implementation — no account required.

== Description ==

Shopwalk makes your WooCommerce store accessible to AI shopping assistants like Claude, ChatGPT, and others — for free, with no account required.

**Free features (no account required):**

* **Full UCP implementation** — AI agents can query your store directly via standard REST endpoints
* Products endpoint: `/wp-json/shopwalk/v1/products` — paginated product catalog with full data
* Store endpoint: `/wp-json/shopwalk/v1/store` — store metadata and capabilities
* Single product: `/wp-json/shopwalk/v1/products/{id}` — full product detail with variations
* Categories: `/wp-json/shopwalk/v1/categories` — product category tree

**With Shopwalk account (free to connect):**

* Appear in Shopwalk AI shopping results across the Shopwalk network
* Real-time catalog sync — products updated automatically as you add or change them
* Partner analytics dashboard at shopwalk.com/partners

= How it works =

1. Install the plugin — your UCP endpoints are immediately active
2. AI agents can discover and query your store directly
3. Optionally connect to Shopwalk to appear in AI shopping results across the network

= Why Shopwalk? =

AI shopping is the future of commerce. Your customers are using AI assistants to find and buy products. Shopwalk makes sure your store shows up when they search.

The plugin is free. Shopwalk only charges a 5% commission on purchases completed through the Shopwalk platform — and only when native checkout is available. No monthly subscription. No upfront cost.

= Works on shared hosting =

The catalog sync works via outbound push from your server to Shopwalk. It does not require Shopwalk to connect inbound to your server, so it works on all shared hosting including Bluehost, SiteGround, and others.

== Installation ==

1. Upload the `shopwalk-ai` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Search for "Shopwalk"**
2. Activate the plugin
3. Go to **WooCommerce → Settings → Shopwalk**
4. Your UCP endpoints are immediately active — no account needed
5. Click **"Connect your Store to Shopwalk — Free"** to join the Shopwalk network and get discovered by AI shoppers

== Frequently Asked Questions ==

= Does this work without creating a Shopwalk account? =

Yes. The UCP product, store, and category endpoints work immediately after install with no account required. AI agents can query your store directly.

= What data is sent to Shopwalk? =

Nothing is sent to Shopwalk until you enter a license key and activate it. Once activated, your product catalog (names, descriptions, prices, image URLs, availability) is sent to Shopwalk to enable AI shopping discovery.

= What is the 5% commission? =

Shopwalk charges 5% on purchases completed through the Shopwalk platform — only when native checkout is available and only when a Shopwalk AI agent processes the transaction. There is no monthly subscription fee. The plugin is free.

= Does this work on Bluehost or shared hosting? =

Yes. The catalog sync pushes data outbound from your WordPress server to Shopwalk. It does not require Shopwalk to connect inbound to your server, so it works on all shared hosting.

= What is UCP? =

UCP (Universal Commerce Protocol) is an open standard that lets AI agents discover, browse, and purchase from any store that implements it. Installing this plugin makes your WooCommerce store UCP-compliant.

= Do I need to pay for the Shopwalk service? =

No. Connecting your store to the Shopwalk network is free. Shopwalk only earns money when it processes a completed purchase — 5% of the transaction value. If no purchases go through Shopwalk, you pay nothing.

== External Services ==

This plugin optionally connects to the Shopwalk service (https://shopwalk.com) when a license key is activated by the store owner.

When a license key is entered and activated, this plugin sends the following data to Shopwalk's servers at api.shopwalk.com:

* Product names, descriptions, short descriptions, and SKUs
* Product prices and regular/sale prices
* Product images (URLs only — images are not uploaded)
* Product availability (in stock / out of stock) and stock quantity
* Product categories and tags
* Product page URLs
* Store metadata (name, URL, currency, WooCommerce version)

This data is used to make the store discoverable through Shopwalk's AI shopping platform.

**No data is sent to Shopwalk when the plugin is installed without a license key.**

Shopwalk Terms of Service: https://shopwalk.com/terms
Shopwalk Privacy Policy: https://shopwalk.com/privacy

== Screenshots ==

1. Free plugin settings page — UCP status and connect CTA
2. Licensed dashboard — connected status and Partners Portal link

== Changelog ==

= 2.0.0 =
* Complete rewrite — clean, focused UCP implementation
* Two-state plugin: free (UCP only) and licensed (Shopwalk network)
* Free state: zero API calls, zero data sent to Shopwalk
* Licensed state: outbound catalog sync, Partners Portal magic link
* Removed all Pro subscription features (checkout, orders, CDN, billing)
* Full WP.org compliance
* Minimal WP Admin dashboard

= 1.13.0 =
* Previous Pro plugin release

== Upgrade Notice ==

= 2.0.0 =
Major rewrite. If you were using the previous version with a license key, your key still works — enter it in the new settings page to restore Shopwalk network connectivity.
