=== Shopwalk ===
Contributors: shopwalkinc
Tags: woocommerce, ai, ucp, shopping, commerce, ai-shopping
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.17
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WooCommerce store discoverable by AI shopping agents. Free — no account required.

== Description ==

Shopwalk makes your WooCommerce store accessible to AI shopping assistants like Claude, ChatGPT, and others — for free, with no account required.

**Free features (no account required):**

* **Full UCP implementation** — AI agents can query your store directly via standard REST endpoints
* Products endpoint: `/wp-json/shopwalk/v1/products` — paginated product catalog with full data
* Store endpoint: `/wp-json/shopwalk/v1/store` — store metadata and capabilities
* Single product: `/wp-json/shopwalk/v1/products/{id}` — full product detail with variations
* Categories: `/wp-json/shopwalk/v1/categories` — product category tree

**With a free Shopwalk account:**

* Appear in Shopwalk AI shopping results across the Shopwalk network
* Real-time catalog sync — products updated automatically as you add or change them
* Partner analytics dashboard at shopwalk.com/partners

= How it works =

1. Install the plugin — your UCP endpoints are immediately active
2. AI agents can discover and query your store directly
3. Optionally connect to Shopwalk to appear in AI shopping results across the network

= Why Shopwalk? =

AI shopping is the future of commerce. Your customers are using AI assistants to find and buy products. Shopwalk makes sure your store shows up when they search.

The plugin is free. Connecting your store to the Shopwalk network is free.

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

Nothing is sent to Shopwalk until you connect your store. Once connected, your product catalog (names, descriptions, prices, image URLs, availability) is sent to Shopwalk to enable AI shopping discovery.

= Does this work on Bluehost or shared hosting? =

Yes. The catalog sync pushes data outbound from your WordPress server to Shopwalk. It does not require Shopwalk to connect inbound to your server, so it works on all shared hosting.

= What is UCP? =

UCP (Universal Commerce Protocol) is an open standard that lets AI agents discover, browse, and interact with any store that implements it. Installing this plugin makes your WooCommerce store UCP-compliant.

= Is Shopwalk free? =

Yes. Connecting your store to the Shopwalk network is free.

= What happens if I deactivate or delete the plugin? =

Deactivating or deleting the plugin stops catalog sync — no more product updates will be sent to Shopwalk. Your store data and Shopwalk account remain intact. You can reconnect at any time by reinstalling the plugin and signing in at shopwalk.com/partners. If you connect again with the same email address, you'll be taken directly to your existing account.

== External Services ==

This plugin optionally connects to the Shopwalk service (https://shopwalk.com) when the store owner connects their store account.

When connected, this plugin sends the following data to Shopwalk's servers at api.shopwalk.com:

* Product names, descriptions, short descriptions, and SKUs
* Product prices and regular/sale prices
* Product images (URLs only — images are not uploaded)
* Product availability (in stock / out of stock) and stock quantity
* Product categories and tags
* Product page URLs
* Store metadata (name, URL, currency, WooCommerce version)

This data is used to make the store discoverable through Shopwalk's AI shopping platform.

**No data is sent to Shopwalk when the plugin is installed without connecting a store account.**

Shopwalk Terms of Service: https://shopwalk.com/terms
Shopwalk Privacy Policy: https://shopwalk.com/privacy

== Screenshots ==

1. Free plugin settings page — UCP status and connect CTA
2. Connected dashboard — sync status and Partners Portal link

== Changelog ==

= 2.0.0 =
* Complete rewrite — clean, focused UCP implementation
* Two-state plugin: free (UCP only) and connected (Shopwalk network)
* Free state: zero API calls, zero data sent to Shopwalk
* Connected state: outbound catalog sync, Partners Portal magic link
* Removed all Pro subscription features (checkout, orders, CDN, billing)
* Full WP.org compliance
* Minimal WP Admin dashboard

= 1.13.0 =
* Previous release

== Upgrade Notice ==

= 2.0.0 =
Major rewrite. If you were using the previous version, your existing Shopwalk account is still active — sign in at shopwalk.com/partners to reconnect your store.


 
