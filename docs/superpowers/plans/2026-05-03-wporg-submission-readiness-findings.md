# WP.org Submission Readiness — Audit Findings

**Summary:** **9 BLOCKER · 25 MAJOR · 41 MINOR** across 8 bundles. Generated 2026-05-03 by parallel audit subagents per plan Phase 2.

> Note: Some findings overlap across bundles (e.g. uninstall completeness shows up in both F-F and F-G). De-duplicated in this document; canonical row marked with **[CANON]** when an overlap exists.

Severity scale:
- **BLOCKER** — must fix for WP.org submission (reviewer would reject) OR contradicts an explicit readme claim
- **MAJOR** — exploitable, broken design, or guideline-violating; should fix
- **MINOR** — quality / hardening / cosmetic

---

## Bundle A — Admin pages (14 findings)

Files: `includes/admin/class-dashboard.php`, `includes/admin/class-self-test.php`, `includes/shopwalk/class-shopwalk-dashboard-panel.php`, `includes/shopwalk/class-shopwalk-connect.php`

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-A-1 | **BLOCKER** | class-dashboard.php:464-497 | `render_styles()` injects raw `<style>` block in admin page body. WP.org Plugin Check missed it but reviewers consistently reject this pattern. Move to a CSS file + `wp_enqueue_style`. |
| F-A-2 | MAJOR | class-dashboard.php:27 + class-self-test.php:39 | Duplicate `add_action('wp_ajax_shopwalk_self_test', …)` — last-loaded wins. Pick one canonical handler (the dashboard's is richer). |
| F-A-3 | MAJOR | class-dashboard.php:328-340 | Large unconnected-state marketing copy hardcoded English; not wrapped in `__()`. |
| F-A-4 | MAJOR | class-dashboard.php:820, 1061, 1147, 1224, 1283, 1297, etc. | ~15 hardcoded English strings inside `wp_send_json_error`/`wp_send_json_success` `message` fields. Render in admin UI via JS. Should be `__()`. |
| F-A-5 | MAJOR | class-dashboard.php:288 | `'Loading sync status...'` hardcoded. |
| F-A-6 | MAJOR | class-dashboard.php:501-812 | Entire `admin_js()` JS string contains user-visible English ("Testing…", "Disconnect from Shopwalk?", "Pause AI discovery?", etc.). None translatable. Pass through `wp_localize_script` with `__()`-built strings table. |
| F-A-7 | MINOR | class-dashboard.php:244 | `sprintf( '%d products · Plugin v%s', … )` — unwrapped format. |
| F-A-8 | MINOR | class-dashboard.php:357 | Inline `onclick="navigator.clipboard.writeText(…)"`. |
| F-A-9 | MINOR | class-dashboard.php:1313 | `'1' === $_POST['enable']` reads `$_POST` without `wp_unslash`/`sanitize_text_field`. PHPCS will flag. |
| F-A-10 | MINOR | class-dashboard.php:415 | Translatable string with escaped apostrophe. |
| F-A-11 | MINOR | class-shopwalk-dashboard-panel.php:30 | `human_time_diff(…) . ' ago'` concatenates English `' ago'` onto translated output. |
| F-A-12 | MINOR | class-dashboard.php:233-234, :859, :1179 | `wp_count_posts('product')->publish ?? 0` will fatal if `wp_count_posts` returns false. |
| F-A-13 | MINOR | class-dashboard.php:286-298 | `render_sync_tool($tier)` — `$tier` arg never used. |
| F-A-14 | MINOR | class-dashboard.php:32-34 | `wp_schedule_event` runs on every `admin_init` page load gated only by `wp_next_scheduled` + `is_valid()`. May double-schedule with `Shopwalk_Connect::init()`. |

**Bundle A clean items** (verified, not findings): C1 sanitization, C2 escaping, C3 capability checks (all admin AJAX endpoints have `current_user_can('manage_woocommerce')`), C4 nonces (`check_ajax_referer` everywhere), C12 no premature output. The `phpcs:disable WordPress.Security.NonceVerification.Recommended` in `class-shopwalk-connect.php:74` is justified — state-nonce pattern is correctly implemented (32-char random, 10-min transient, `hash_equals` compare, one-shot).

---

## Bundle B — REST surface (14 findings)

Files: `includes/core/class-ucp-{discovery,products,orders,checkout,direct-checkout,webhook-subscriptions,response}.php`

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-B-1 | **BLOCKER** | class-ucp-direct-checkout.php:310-376 | **Tier-1 file makes outbound HTTP to api.shopwalk.com.** Hard-coded host at line 324, `wp_remote_post` fires at 359. License-gated at runtime so the readme's "zero outbound traffic without a license" claim holds, but `includes/core/` knows about the Shopwalk URL and posts to it. Move to `includes/shopwalk/`. |
| F-B-2 | MAJOR | class-ucp-checkout.php:88-92, :191, :239, :288 | `permission_optional_buyer` returns true unconditionally on POST/PUT/POST-complete/POST-cancel. Handlers don't check session ownership against caller's OAuth client_id. Anyone who knows a session id (chk_<random>) can update/complete/cancel another agent's session. |
| F-B-3 | MAJOR | class-ucp-webhook-subscriptions.php:46,56,61 + class-ucp-orders.php:34,43,52 | `permission_callback => '__return_true'` on write endpoints; auth done in handler. Brittle — one forgotten check makes the route unauthenticated. Should be a real permission_callback that runs `authenticate_request`. |
| F-B-4 | MAJOR | class-ucp-direct-checkout.php:84 | License-key compared with `!==` (not `hash_equals`) — timing attack vector for license recovery. |
| F-B-5 | MAJOR | class-ucp-checkout.php:113-147, :191-227 | Raw `$body['line_items']/buyer/fulfillment/payment` not per-field sanitized at the create/update boundary; stored as JSON, returned to clients verbatim, consumed by `build_wc_order_from_session`. |
| F-B-6 | MAJOR | class-ucp-webhook-subscriptions.php:85 | **(See F-D-1 — SSRF on callback_url; canonical in Bundle D.)** [CANON in D] |
| F-B-7 | MAJOR | class-ucp-direct-checkout.php:266, :397-403 | `return_url` stored via `esc_url_raw` and used as post-payment redirect with no host whitelist. Open-redirect / phishing primitive. Restrict to `*.shopwalk.com` or the agent's registered host. |
| F-B-8 | MINOR | class-ucp-direct-checkout.php:359 | No explicit `sslverify` (defaults true, OK); URL hardcoded to api.shopwalk.com — fine, but document the SHOPWALK_API_URL override constant must be HTTPS. |
| F-B-9 | MINOR | class-ucp-products.php:21-39 | `__return_true` on public catalog read — intentional per spec; consider Cache-Control + rate-limit. |
| F-B-10 | MINOR | class-ucp-orders.php:113-117, :132-136 | Per-route arg schema lacks `sanitize_callback` for `id`; relies on URL regex. Cosmetic. |
| F-B-11 | MINOR | class-ucp-webhook-subscriptions.php:218-222 | `find_by_event` does `SELECT *` then filters in PHP — see F-D-4 (canonical in D). |
| F-B-12 | MINOR | class-ucp-checkout.php:104-111 | Idempotency-Key transient keyed only on agent-supplied string; mix `client_id` into the key to prevent cross-agent collision. |
| F-B-13 | MINOR | class-ucp-discovery.php:32, :41 | Plugin name + version exposed in discovery doc — standard fingerprinting, not actionable. |
| F-B-14 | MINOR | class-ucp-orders.php:72-73 | `(int) $request->get_param('limit') ?: 25` — non-numeric input silently coerced. Cosmetic. |

**Bundle B clean items**: C5 prepared statements (every `$wpdb` call uses `prepare()` correctly with %s/%d, table names from trusted `UCP_Storage::table()` constant). REST endpoint inventory captured 13 routes; auth model is OAuth Bearer or anonymous-machine-to-machine — no cookie-AJAX surface so C4 nonce is N/A.

---

## Bundle C — OAuth + token security (13 findings)

Files: `includes/core/class-ucp-oauth-server.php`, `includes/core/class-ucp-oauth-clients.php`, `includes/core/class-ucp-signing.php`, `tests/PkceTest.php`

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-C-1 | **BLOCKER** | class-ucp-oauth-server.php:257-278 | **Refresh tokens do NOT rotate.** `exchange_refresh_token` issues new access token but doesn't revoke or replace the refresh token. Same refresh token reusable for full 30-day TTL. Violates OAuth 2.1 §4.12. |
| F-C-2 | **BLOCKER** | class-ucp-oauth-server.php:136-227 | **PKCE is optional** (clients omitting `code_challenge` skip it entirely) and **`plain` method is accepted** (lines 139, 220-222). OAuth 2.1 forbids `plain` and requires PKCE for public clients. |
| F-C-3 | MAJOR | class-ucp-oauth-server.php:393-416 | `lookup_token` does O(N) bcrypt verifies per request (one per non-revoked, non-expired row of that type) because bcrypt salts make column non-deterministic. DoS amplifier. Use HMAC-SHA256 with server pepper for O(1) index lookup + constant-time confirm. |
| F-C-4 | MAJOR | class-ucp-oauth-server.php:106, :161-163 | `state` not required by server; redirect builds via `http_build_query` and returns 302 via WP_REST_Response (unusual). Verify Location header is honored vs JSON body returned. |
| F-C-5 | MAJOR | class-ucp-oauth-server.php:50-54 | `/oauth/authorize` accepts both GET and POST. POST + implicit consent + no nonce = CSRF account-linking primitive (logged-in WP user can be tricked into POSTing, mints code silently, redirects). Restrict to GET. |
| F-C-6 | MAJOR | class-ucp-oauth-server.php:97-98, :145-154 | **Implicit consent.** Mints authorization code for any registered client/redirect_uri the moment the user is logged in. No interactive consent screen. Combined with F-C-5, exploitable. |
| F-C-7 | MAJOR | class-ucp-oauth-server.php:177-193 + class-ucp-oauth-clients.php:127-133 | No rate-limiting on `verify_secret` (bcrypt, slow but unmetered). Brute force via repeated /token POSTs feasible. |
| F-C-8 | MINOR | class-ucp-oauth-server.php:343 | `issue_token` is `public static` — could mint tokens bypassing /authorize. Should be `private` or guarded. |
| F-C-9 | MINOR | class-ucp-oauth-clients.php:204-210 + server.php:355 + signing.php:43 | `wp_generate_password` fallback after `random_bytes` throw — dead code on modern PHP, remove for clarity. |
| F-C-10 | MINOR | class-ucp-signing.php:96-105 | `verify_request` returns TRUE when no signature header present ("caller decides"). Footgun — every caller must remember to gate first. Rename or split. |
| F-C-11 | MINOR | class-ucp-signing.php:121-141 | `verify_request_jwt` falls back to HMAC even when JWK is configured — downgrade attack. If client has `signing_jwk`, HMAC fallback should be off. |
| F-C-12 | MINOR | class-ucp-oauth-server.php:282-299 | `/revoke` does not authenticate the client (RFC 7009 §2.1 requires it for confidential clients). Self-DoS only, but should authenticate. |
| F-C-13 | MINOR | class-ucp-oauth-server.php:393-416 | `lookup_token` returns row without verifying client_id — caller checks after. Refactor risk. |

**Bundle C clean items**: O3 (token storage hashed with bcrypt), O6 (redirect_uri exact-match with `hash_equals`), O7 (constant-time comparisons everywhere), O8 (client secret hashed). C5 (prepared statements) all clean. C6 (no eval/create_function/assert) clean. PkceTest covers the S256 derivation with the RFC 7636 reference vector.

---

## Bundle D — Webhook delivery (13 findings)

Files: `includes/core/class-ucp-webhook-delivery.php`, `includes/core/class-ucp-webhook-subscriptions.php`, `includes/core/class-ucp-signing.php`

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-D-1 | **BLOCKER** | class-ucp-webhook-subscriptions.php:85 | **SSRF — `callback_url` validated only by `filter_var(… FILTER_VALIDATE_URL)`.** Accepts `http://`, `file://`, `gopher://`, `http://127.0.0.1`, `http://169.254.169.254` (AWS IMDS), `http://10.0.0.x`, `http://localhost:6379`, etc. Subscribe + delivery POSTs reach internal/cloud-metadata endpoints. **[CANON for F-B-6]** |
| F-D-2 | MAJOR | class-ucp-webhook-delivery.php:297-312 | `wp_remote_post` doesn't pass `redirection => 0`. Even strict subscribe-time validation defeated by attacker https URL that 302s into 169.254.169.254. |
| F-D-3 | MAJOR | class-ucp-webhook-subscriptions.php:46,56,61 | `__return_true` permission_callback on write routes. **[Same pattern as F-B-3]** |
| F-D-4 | MAJOR | class-ucp-webhook-subscriptions.php:214-222 | `find_by_event` does `SELECT *` (no WHERE on event_type because JSON column), filters in PHP. O(N) per order-status hook — DoS / latency vector + agent can spam subscriptions to degrade checkout. |
| F-D-5 | MAJOR | class-ucp-webhook-subscriptions.php:106 + class-ucp-webhook-delivery.php:286 | Subscription HMAC `secret` stored plaintext in `webhook_subscriptions.secret`. DB read = forge events. Encrypt at rest using `wp_salt('auth')` or dedicated install secret. |
| F-D-6 | MAJOR | class-ucp-webhook-delivery.php:362-373 | Failed rows persist with `failed_at` (good) but **no admin/CLI/REST surface** to view dead-letter. Ops blind without raw DB. |
| F-D-7 | MINOR | class-ucp-webhook-delivery.php:294-308 | `Signature-Input` advertises RFC 9421 format but actual signing input is proprietary `webhook-id.timestamp.payload` concatenation. Receivers following advertised format will fail verify; encourages disabling verification. |
| F-D-8 | MINOR | class-ucp-webhook-delivery.php:215, :220, :213 | Payload includes `permalink_url` (contains WC order key — grant-bearing). Sent to every subscribed agent. Acceptable in spec but flag if subscription auth weakens. |
| F-D-9 | MINOR | class-ucp-webhook-delivery.php:300, :32 | `timeout: 15` × `BATCH_SIZE: 50` sequential = 12.5min worst-case in WP-Cron. Slowloris subscriber consumes cron capacity. Shorten timeout, add per-subscription circuit breaker, or async via Action Scheduler. |
| F-D-10 | MINOR | class-ucp-signing.php:97-104 | **[Same as F-C-10]** — `verify_request` returns true on missing header. |
| F-D-11 | MINOR | class-ucp-webhook-delivery.php:332-344 | All 4xx are terminal. 408/425/429 should retry (respect `Retry-After`). |
| F-D-12 | MINOR | class-ucp-webhook-delivery.php:121, :134, :382 | Mixes `current_time('mysql', true)` and `gmdate(…)`. Both UTC today; future maintainer flipping one breaks queue. Pick one helper. |
| F-D-13 | MINOR | class-ucp-webhook-subscriptions.php:218-222 | `SELECT *` pulls `secret` column into PHP memory for every subscription on every event. Exposure via Xdebug/error-handler. Project-only-needed columns. |

**Bundle D clean items**: W1 (`hash_equals` in signing), W3 (no secrets/PAN in payload — only ucp envelope, order id/permalink, line items, totals; no email/phone/address/payment-instrument), W4 (bounded retry: MAX_ATTEMPTS=5, 1m/2m/4m/8m/16m exponential). Tier 1 purity preserved (no `*.shopwalk.com` in delivery).

---

## Bundle E — Payment router + adapters (5 findings)

Files: `includes/core/class-ucp-payment-{router,adapter-stripe,gateway}.php`, `includes/core/interface-ucp-payment-adapter.php`

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-E-1 | MAJOR | class-ucp-payment-adapter-stripe.php:110-120 | **Missing Stripe `Idempotency-Key` header.** Network retry or agent re-submission of `/checkout-sessions/{id}/complete` will create duplicate PaymentIntents → double-authorize. Derive key from order id + pm token. |
| F-E-2 | MINOR | class-ucp-payment-adapter-stripe.php:99 | `'confirm' => 'true'` as string — works, flag only because boolean `true` would coerce to "1" and fail. |
| F-E-3 | MINOR | class-ucp-payment-adapter-stripe.php:136 | Stripe `error.message` echoed verbatim to API caller. PCI-safe (no PAN/CVV) but worth one-line comment. |
| F-E-4 | MINOR | class-ucp-payment-adapter-stripe.php:67-89 | `authorize()` doesn't reject `amount <= 0`. Defensive guard. |
| F-E-5 | MINOR | class-ucp-payment-adapter-stripe.php:114 | `Authorization` header concatenates `$secret_key` directly. Add `trim()` for paste-mistake hygiene. |

**Bundle E clean items (HIGH PCI confidence)**: P1 zero PAN/CVV/track in logs/storage (no log calls anywhere). P2 tokenized credentials only (`pm_*` ingest, refuses without). **P3 PASSES** — Stripe credentials read exclusively from `get_option('woocommerce_stripe_settings')`, no plugin-local key option exists. P5 adapter filter contract documented + tested. The plugin's own `Shopwalk_UCP_Payment_Gateway` is a labelling shim only (`is_available()` returns false, `process_payment()` is a no-op).

---

## Bundle F — Shopwalk Tier 2 disclosure (13 findings)

Files: `includes/shopwalk/class-shopwalk-{license,connector,sync}.php`

**Outbound payload field inventory (from `class-shopwalk-sync.php`):**

Per-product upsert keys: `external_id`, `name`, `description`, `short_description`, `sku`, `price`, `compare_at_price`, **`currency`**, `in_stock`, `source_url`, `categories[]`, `images[]` (`url`, **`alt`**, **`position`**), `op`.

Envelope keys: **`site_url`** (home_url), `sync_type`, `products[]`, **`total_products`** (from `wp_count_posts('product')->publish`).

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-F-1 | **BLOCKER** | class-shopwalk-sync.php:295 | **`total_products` (catalog size) sent on every batch — NOT in readme § External Services.** Aggregate business-intelligence data not disclosed. |
| F-F-2 | **BLOCKER** | class-shopwalk-sync.php:292 + license.php:171, :326 | **`site_url` (store domain) sent in payload — NOT in readme.** Readme discloses where data goes (api.shopwalk.com) but not that the site URL is part of the body. |
| F-F-3 | **BLOCKER** | class-shopwalk-sync.php:266 | **`currency` code sent — NOT in readme.** Readme lists "prices" but not the currency. |
| F-F-4 | **BLOCKER** | class-shopwalk-sync.php:233-244 | **Image `alt` text + `position` sent in `images[]` — NOT in readme.** Readme says "image URLs (images themselves are not uploaded)"; alt text is merchant-authored copy, position is ordering metadata. |
| F-F-5 | MAJOR | class-shopwalk-sync.php:267 | Readme item 4 promises "availability AND stock quantity"; code only sends `in_stock` (bool). README OVERSTATES — either add `stock_quantity` to payload or remove from readme. |
| F-F-6 | MAJOR | class-shopwalk-sync.php:264-265 | Readme item 2 says "regular/sale prices" (plural). Code emits `price` (effective) + `compare_at_price` (regular); no discrete `sale_price` field. Field naming mismatch. |
| F-F-7 | MAJOR | class-shopwalk-dashboard-panel.php:44 | **License key echoed verbatim in WP Admin** as `<code><?php echo esc_html($license_key); ?></code>`. Bearer credential — anyone with admin screen-share/screenshot leaks it. Mask (`sw_site_xxxx…last4`) or hide behind Reveal toggle. |
| F-F-8 | MAJOR | class-shopwalk-sync.php:197 | Sync flush gates on `Shopwalk_License::is_valid()` (string-format check) instead of `is_connected()` (server status). Server-revoked/expired licenses still post product data. Should gate on `is_connected()` or status-aware predicate. |
| F-F-9 | MINOR | class-shopwalk-license.php:315-339 | `Shopwalk_License::deactivate()` clears options but doesn't unschedule `shopwalk_status_poll` cron (owned by `Shopwalk_Connect`). Cron persists, but handler early-returns on empty key — no leak. **[Overlap F-G-2]** |
| F-F-10 | MINOR | class-shopwalk-sync.php:258 | `external_id` (post_id) in payload — undisclosed. Internal correlator, low sensitivity. |
| F-F-11 | MINOR | class-shopwalk-connector.php:97 | **`SYNC_COOLDOWN = 0` (commented "disabled for testing — set to 3600 for production").** Debug constant left in. Reset before submission. |
| F-F-12 | MINOR | uninstall.php:38-50 | Uninstall option list incomplete — missing 11 shopwalk_* options. **[CANON F-G-1]** |
| F-F-13 | MINOR | uninstall.php:52-55 | Uninstall doesn't clear `shopwalk_status_poll` cron. **[CANON F-G-2]** |

---

## Bundle G — Activation, deactivation, uninstall (7 findings)

Files: `includes/class-woocommerce-ucp.php`, `uninstall.php`, `includes/core/class-ucp-bootstrap.php`, `includes/core/class-ucp-storage.php`

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-G-1 | MAJOR **[CANON]** | uninstall.php:38-50 | **11 options written but NOT deleted on uninstall:** `shopwalk_license_needs_activation`, `shopwalk_plan`, `shopwalk_plan_label`, `shopwalk_subscription_status`, `shopwalk_last_status_poll`, `shopwalk_license_status`, `shopwalk_last_heartbeat_at`, `shopwalk_next_billing_at`, `shopwalk_discovery_paused`, `shopwalk_sync_state`, `shopwalk_sync_history`. Readme FAQ promises "deletes every `shopwalk_*` WP option". |
| F-G-2 | MAJOR **[CANON]** | uninstall.php:52-55 | Uninstall does NOT unschedule `shopwalk_status_poll` cron (deactivate does). Forced uninstall without prior deactivation orphans the cron. |
| F-G-3 | MAJOR | class-ucp-direct-checkout.php:55-57 + uninstall.php + class-woocommerce-ucp.php:242-248 | Cron `shopwalk_ucp_direct_checkout_cleanup` is scheduled on every `rest_api_init` but **never unscheduled** in deactivate or uninstall. Orphan cron after plugin removal. |
| F-G-4 | MINOR | uninstall.php:42-44 | Three options listed for deletion (`shopwalk_synced_count`, `shopwalk_last_sync_at`, `shopwalk_notice_dismissed`) have NO writer in current code. Legacy keys; safe to leave or document. |
| F-G-5 | MINOR | class-dashboard.php:109 + uninstall.php | Transient `shopwalk_status_banner` not explicitly deleted. Auto-expires (5min). Inconsistent with explicit `shopwalk_latest_version` cleanup. |
| F-G-6 | MINOR | class-woocommerce-ucp.php:316-320 | `file_put_contents` for `/.well-known/{ucp.php,oauth-authorization-server.php,.htaccess}` unconditionally overwrites. On reactivation intended; could clobber a host-managed `/.well-known/.htaccess`. |
| F-G-7 | MINOR | class-woocommerce-ucp.php:316-320 | `file_put_contents` return value unchecked. On read-only `/.well-known/`, activation succeeds but discovery is broken silently. |

**Bundle G clean items**: **C7 PASSES** — `WooCommerce_UCP::activate()` and everything reachable (Storage::install, Signing::ensure_store_keypair, create_well_known_files, before_woocommerce_init) makes ZERO `wp_remote_*` calls. `plugins_loaded` path also gated: `load_shopwalk()` only runs when license key option present. Readme zero-outbound-traffic-without-license claim **holds at runtime**. C15 idempotency PASSES (option writes guarded with `false === get_option(…)`, all crons check `wp_next_scheduled` first). Five UCP tables correctly dropped on uninstall.

---

## Bundle H — CLI + glue (3 findings)

Files: `includes/core/class-ucp-{cli,self-test,store,sync-trigger}.php`

| ID | Sev | File:Line | Finding |
| --- | --- | --- | --- |
| F-H-1 | MINOR | class-ucp-cli.php:42-72, :131-159, :177-195 | All operator-facing CLI strings hardcoded English (no `__()`/text-domain). |
| F-H-2 | MINOR | class-ucp-self-test.php:42-246 | All check labels/messages returned through AJAX → admin UI — none wrapped in `__()`. (`includes/admin/class-self-test.php:51` does it correctly, proving the project's pattern.) |
| F-H-3 | MINOR | class-ucp-cli.php:84 | `UCP_CLI::list` shadows PHP reserved word `list` (legal since PHP 7+, stylistic). |

**Bundle H clean items**: CLI surface is hard-gated to WP-CLI runtime (`defined('WP_CLI') && WP_CLI`); not bound to AJAX/REST. Self-test outbound HTTP is self-loopback only (`home_url`/`rest_url`) — no external traffic. C5/C6 clean.

---

## Cross-cutting themes

1. **`__return_true` permission_callback pattern is endemic** (F-B-2, F-B-3, F-D-3) — auth done in handler. Functionally enforced today, brittle. 3 routes affected across orders, checkout-sessions, webhook-subscriptions.
2. **i18n debt** (F-A-3, A-4, A-5, A-6, A-7, A-10, A-11; F-H-1, H-2) — large amounts of admin/CLI/AJAX-message English not translatable. ~50+ strings.
3. **Disclosure drift** (F-F-1 through F-F-6) — readme § External Services and actual sync payload have diverged. 4 BLOCKER undisclosed fields + 2 MAJOR overstatements.
4. **Lifecycle cleanup drift** (F-G-1, F-G-2, F-G-3, F-F-12, F-F-13) — new options/crons added without uninstall.php / deactivate keeping pace.
5. **OAuth maturity** (F-C-1, F-C-2, F-C-5, F-C-6) — implementation is OAuth 2.0-shaped but not OAuth 2.1-compliant. PKCE optional, refresh tokens don't rotate, implicit consent + POST authorize.
6. **SSRF on agent-controlled URLs** (F-D-1, F-D-2 webhook callback; F-B-7 return_url open-redirect) — agent inputs flow into outbound HTTP / redirects without scheme/host validation.

---

## Plugin Check vs. manual audit

The `WP.org Plugin Check` workflow on PR #46 was GREEN. The manual audit surfaced 9 BLOCKERs and 25 MAJORs that Plugin Check missed. This is expected — Plugin Check is a static-rule sniff, not a logic auditor. The findings above are dominated by:
- Logic-level issues (auth bypass, ownership checks, SSRF behavior) that no static rule can catch
- Disclosure-vs-code drift that requires reading both readme + code
- OAuth/security design gaps that need protocol knowledge

CI green ≠ submission-ready. The audit is the second gate.
