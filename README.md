# Shopwalk for WooCommerce

[![Plugin Version](https://img.shields.io/badge/version-3.0.40-blue)](https://github.com/shopwalk-inc/shopwalk-woocommerce/releases)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-a46497)](https://woocommerce.com)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](https://php.net)
[![UCP](https://img.shields.io/badge/UCP-1.0-0ea5e9)](https://ucp.dev)
[![HPOS](https://img.shields.io/badge/HPOS-compatible-success)](https://developer.woo.com/2022/09/14/high-performance-order-storage-progress-report/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Make any WooCommerce store fully purchasable by UCP-compliant AI shopping agents.** Free, standalone, no account required.

---

## What this plugin does

This plugin implements the [Universal Commerce Protocol (UCP)](https://ucp.dev) on top of WooCommerce. Once activated, your store can be discovered, browsed, and transacted by any UCP-compliant agent — Claude, ChatGPT, Anthropic, LangChain, custom agents — without any external service or account.

The plugin's **primary identity is "the UCP adapter for WooCommerce."** Optional Shopwalk integration is layered on top for merchants who want real-time push sync to the Shopwalk network.

## What you get out of the box (Tier 1 — UCP Core)

- Full UCP REST surface under `/wp-json/ucp/v1/`
- OAuth 2.0 authorization server (`/authorize`, `/token`, `/revoke`, `/userinfo`) bound to WordPress user accounts
- Checkout session lifecycle (`create`, `update`, `complete`, `cancel`)
- Order retrieval and fulfillment events for the OAuth-authenticated buyer
- Outbound webhook delivery with HMAC signing, exponential backoff, and a dead-letter queue
- **"Pay via UCP" WooCommerce payment gateway** registered automatically so agents can complete checkout
- Discovery doc at `/.well-known/ucp` and RFC 8414 OAuth metadata at `/.well-known/oauth-authorization-server`, served via static PHP shims that work on Apache shared hosts (Bluehost, SiteGround, HostGator, …)
- WP-CLI management commands (`wp shopwalk client create|list|delete|rotate-secret`)
- Self-test diagnostic runner with bidirectional reachability probe

With **zero outbound HTTP calls to Shopwalk** when no license is configured.

## Optional: Shopwalk integration (Tier 2)

Activates only when a Shopwalk license is entered in the dashboard. Adds:

- Real-time push sync of products to the Shopwalk network
- Premier placement on shopwalk.com
- Analytics dashboard at shopwalk.com/partners
- Faster index updates than the UCP pull path alone provides

Removing the `includes/shopwalk/` directory leaves Tier 1 fully functional. Strict tier separation.

---

## UCP REST API

All endpoints live under `/wp-json/ucp/v1/` and conform to the [UCP specification](https://ucp.dev).

### Discovery

| Method | Path | Purpose |
|---|---|---|
| GET | `/.well-known/ucp` | UCP service discovery |
| GET | `/.well-known/oauth-authorization-server` | OAuth 2.0 server metadata (RFC 8414) |

### OAuth 2.0 Server

| Method | Path | Purpose |
|---|---|---|
| GET  | `/wp-json/ucp/v1/oauth/authorize` | Authorization endpoint (PKCE supported — S256 + plain) |
| POST | `/wp-json/ucp/v1/oauth/token` | Token endpoint (`authorization_code`, `refresh_token`) |
| POST | `/wp-json/ucp/v1/oauth/revoke` | Token revocation (RFC 7009) |
| GET  | `/wp-json/ucp/v1/oauth/userinfo` | OIDC userinfo — returns the linked WC customer |

### Checkout

| Method | Path | Purpose |
|---|---|---|
| POST   | `/wp-json/ucp/v1/checkout-sessions` | Create checkout session |
| GET    | `/wp-json/ucp/v1/checkout-sessions/{id}` | Get session state |
| PUT    | `/wp-json/ucp/v1/checkout-sessions/{id}` | Update (buyer, address, fulfillment) |
| POST   | `/wp-json/ucp/v1/checkout-sessions/{id}/complete` | Finalize, charge, create WC order |
| POST   | `/wp-json/ucp/v1/checkout-sessions/{id}/cancel` | Cancel and release stock |

### Orders

| Method | Path | Purpose |
|---|---|---|
| GET | `/wp-json/ucp/v1/orders` | List orders for the authenticated buyer |
| GET | `/wp-json/ucp/v1/orders/{id}` | Order detail |
| GET | `/wp-json/ucp/v1/orders/{id}/events` | Fulfillment events log |

### Webhooks (outbound)

| Method | Path | Purpose |
|---|---|---|
| POST   | `/wp-json/ucp/v1/webhooks/subscriptions` | Agent subscribes to order events |
| GET    | `/wp-json/ucp/v1/webhooks/subscriptions/{id}` | Get subscription |
| DELETE | `/wp-json/ucp/v1/webhooks/subscriptions/{id}` | Unsubscribe |

Delivered events: `order.created`, `order.processing`, `order.shipped`, `order.delivered`, `order.canceled`, `order.refunded`.

---

## Payment — agent-native, using your existing WC gateway

**The plugin owns zero payment configuration.** It reuses whatever gateway you already have set up in WooCommerce (WC Stripe, WC PayPal, Square, Amazon Pay, anything on the marketplace) and never asks you for a second set of keys.

The flow:

1. The agent creates a UCP checkout session and tokenizes a payment method with its own SDK (e.g. Stripe.js → `pm_…`)
2. The agent calls `POST /wp-json/ucp/v1/checkout-sessions/{id}/complete` with `payment.gateway: "stripe"` + the tokenized credential
3. The plugin's **payment router** looks up the adapter registered for that gateway id
4. The adapter pulls the merchant's existing gateway credentials from WooCommerce (for Stripe: from `woocommerce_stripe_settings`) and authorizes the payment
5. On success, the WC order advances to `processing` via `$order->payment_complete()` — same lifecycle as a native checkout
6. The webhook worker fires `order.created` + `order.processing` to subscribed UCP agents

No buyer hand-off. No duplicate configuration. If the agent can't auto-authorize (e.g. 3D Secure is required), the session falls back to returning `order.payment_url` so the agent can escalate.

### Supported gateways

- **Stripe** — ships in-box, uses the WooCommerce Stripe Gateway's existing keys

### Adding more gateways

Third parties (or merchants) can register adapters via filter without touching plugin core:

```php
add_filter( 'shopwalk_payment_adapters', function ( $adapters ) {
    $adapters['ppcp'] = 'My_PPCP_UCP_Adapter'; // must implement UCP_Payment_Adapter_Interface
    return $adapters;
} );
```

The `UCP_Payment_Adapter_Interface` has four methods: `id()`, `is_ready()`, `discovery_hint()`, `authorize($order, $payment)`. Ready adapters are auto-advertised in `/.well-known/ucp`'s `payment_handlers` field so agents know which gateways this store accepts before they start a session.

A WooCommerce payment method named **"Pay via UCP"** is registered for labeling only — so WC reports, refund UI, and the Orders table can identify UCP-initiated orders. It is never exposed on the storefront checkout form.

### Dashboard

**WP Admin → UCP → Payments** shows every registered adapter, ready/not-ready state, and deep-links into the WC settings page for each gateway so setup never leaves context.

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 8.0 (tested up to 9.5) |
| PHP | 8.0 |
| SSL | Required (HTTPS) |

**WooCommerce feature compatibility:** HPOS (High-Performance Order Storage) ✅ · Cart/Checkout Blocks ✅

---

## Installation

### From the WordPress Plugin Directory (recommended)

1. **Plugins → Add New**, search for **Shopwalk for WooCommerce**
2. **Install Now**, then **Activate**
3. Visit **UCP** in the WP Admin sidebar
4. Click **Test Connectivity** and **Local Self-Test** to verify your environment

### Manual installation

1. Download the latest release zip from the [Releases page](https://github.com/shopwalk-inc/shopwalk-woocommerce/releases)
2. **Plugins → Add New → Upload Plugin**, select the zip, activate
3. Visit **UCP** to run the self-test

---

## Privacy & Data

When **no license** is configured, the plugin makes **zero outbound HTTP calls**. It is fully self-contained.

When a Shopwalk license is entered, the plugin sends product data to `https://api.shopwalk.com` over authenticated HTTPS:

- Product names, descriptions, SKUs, prices, stock, categories, permalinks, image URLs (the images themselves are not uploaded)

No customer data, addresses, or payment information is ever sent to Shopwalk. All payment processing remains in WooCommerce.

- [Shopwalk Privacy Policy](https://shopwalk.com/privacy)
- [Shopwalk Terms of Service](https://shopwalk.com/terms)

---

## Uninstall

Deactivating the plugin stops WP-Cron jobs and removes the static `.well-known` files. Deleting the plugin (WP Admin → Plugins → Delete) drops every `wp_ucp_*` table, deletes every `shopwalk_*` option, and removes all scheduled crons. Your WooCommerce data is untouched.

---

## Contributing

```bash
git clone https://github.com/shopwalk-inc/shopwalk-woocommerce.git
cd shopwalk-woocommerce
composer install

# WordPress coding standards
./vendor/bin/phpcs --standard=WordPress .

# PHPUnit
./vendor/bin/phpunit
```

Report security vulnerabilities to **security@shopwalk.com** — not as public GitHub issues.

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

**3.0.40** — Current. HPOS + Blocks compatibility declared; WC logger integration; nonce + capability hardening on admin AJAX handlers.

**3.0.0** — Complete rewrite as a UCP-compliant adapter. Primary identity is now "UCP adapter for WooCommerce." Namespace migrated from `/wp-json/shopwalk/v1/` to `/wp-json/ucp/v1/`. OAuth 2.0 server, order endpoints, outbound webhook delivery, "Pay via UCP" gateway, discovery docs. Catalog endpoints removed — shopwalk-feeds now reads the standard WC REST API directly.

**2.x** — End of life.

**1.x** — End of life.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
