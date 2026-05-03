# UCP for WooCommerce — WP.org Submission Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `ucp-for-woocommerce` to wordpress.org as the canonical (source-of-truth) distribution. Land the in-flight v3.1.0 release, branch off main for a v3.1.1 readiness pass, perform a strict file-by-file code re-audit against WP.org guidelines, fix every gap, drive a complete customer-path end-to-end test on shopwalkstore.com (shop1), then submit.

**Architecture:** Three sequential workstreams on three commits/PRs:
1. **v3.1.0 lands** (PR #46 already green) → tag → GitHub Release zip exists.
2. **v3.1.1 readiness PR** off `main` containing audit fixes, the screenshot captions fix, the PHP version drift fix, the regenerated POT, and any concrete bugs the audit surfaces.
3. **Customer-path verification** on a real WP+WC site (shopwalkstore.com / shop1) using the v3.1.1 zip, exercising every public surface a merchant or agent would touch. No SSH/SQL/admin shortcuts — drive the UI/REST as the actual user/agent would (per the "Test the customer path" rule).

**Tech Stack:** PHP 8.1+, WordPress 6.0+, WooCommerce 8.0+, PHPUnit 10, PHPCS (WordPress + WordPress-Extra), WP.org Plugin Check plugin, WP-Cron, WP REST API, OAuth 2.0, HMAC-SHA256, Stripe API.

---

## Pre-flight context (read before starting)

**Current state at plan-write time:**
- Branch: `feat/variations-in-products-endpoint`, PR #46 OPEN, all CI checks green (`PHPCS`, `PHPUnit 8.1/8.2/8.3`, `WP.org Plugin Check`, `CodeQL`).
- Header version: `3.1.0` (in both `ucp-for-woocommerce.php:6,26` and `readme.txt:9`).
- `RELEASE.md` documents the GitHub-tag → zip → SVN flow already.
- Banner (1544×500, 772×250) and icon (128, 256) PNGs are present in `assets/`.
- POT file present at `languages/ucp-for-woocommerce.pot`.
- 7 PHPUnit test files, all passing in CI.
- `WP.org Plugin Check` workflow already runs on every PR — green on PR #46.

**Already-identified gaps** (these are work items, not assumptions):
1. `assets/` has no `screenshot-1.png` … `screenshot-4.png`. WP.org needs 4 to match captions in `readme.txt:110-115`. (Note: screenshots live in SVN `/assets/` root, NOT in the trunk zip — but they must exist before SVN upload. User will produce them on shopwalkstore.com / shop1.)
2. `readme.txt` lines 112–114 contain literal `→` strings instead of the `→` Unicode arrow they should be.
3. `readme.txt:76` FAQ prose says "PHP 8.0 or later" while the header (`readme.txt:6`, `ucp-for-woocommerce.php:15`) says `Requires PHP: 8.1`. Drift.
4. `readme.txt:5` `Tested up to: 6.9` — needs verification against current latest stable WP at submission time.

**Outbound HTTP inventory** (verified by grep at plan-write time, for the audit):
- `class-ucp-webhook-delivery.php:297` — to agent-configured subscription URLs (Tier 1, expected).
- `class-ucp-direct-checkout.php:359` — needs audit; readme claims zero outbound traffic to Shopwalk without a license, so this call must terminate at the agent's URL or a per-checkout target, not at `api.shopwalk.com`.
- `class-ucp-payment-adapter-stripe.php:110` — to Stripe (Tier 1, expected).
- `class-ucp-self-test.php:43` — self-loopback to `home_url('/.well-known/ucp')` (diagnostic, not call-home).
- `class-shopwalk-license.php`, `class-shopwalk-connect.php`, `class-shopwalk-sync.php` — Tier 2, expected after license.

**Memory rules in effect:**
- Always branch from main (`git checkout main && git pull` before `git checkout -b`).
- Version bumps go in the same commit as the change.
- WP.org is source of truth for `ucp-for-woocommerce`.
- Drive the customer path through real UI/REST, never SSH/SQL/admin shortcuts.
- Don't commit binaries or one-time cleanup scripts.

---

## Phase 0: Land PR #46 (v3.1.0 release on main)

### Task 0.1: Verify PR #46 is mergeable

**Files:**
- Read-only

- [ ] **Step 1: Re-check CI status**

Run: `gh pr view 46 --json statusCheckRollup,mergeable,mergeStateStatus`
Expected: All checks `SUCCESS`, `mergeable: "MERGEABLE"`, `mergeStateStatus: "CLEAN"`.

- [ ] **Step 2: Re-check the diff is what we expect (variations only)**

Run: `gh pr diff 46 --patch | head -200`
Expected: Changes scoped to `includes/core/class-ucp-products.php`, `tests/ProductsVariationsTest.php`, version bump in `ucp-for-woocommerce.php` and `readme.txt`, changelog entry.

If the diff has scope creep, stop and resolve before merging.

### Task 0.2: Merge PR #46 and tag v3.1.0

- [ ] **Step 1: Squash-merge**

Run: `gh pr merge 46 --squash --delete-branch`
Expected: PR closed, branch deleted on remote.

- [ ] **Step 2: Sync local main**

Run: `git checkout main && git pull --ff-only`
Expected: `main` advances to the squashed commit.

- [ ] **Step 3: Confirm header version on main**

Run: `grep -E "^ \* Version:|^define\( 'WOOCOMMERCE_UCP_VERSION'" ucp-for-woocommerce.php`
Expected: Both lines show `3.1.0`.

Run: `grep "^Stable tag:" readme.txt`
Expected: `Stable tag: 3.1.0`.

If any drift, do NOT tag — open a quick fix PR first.

- [ ] **Step 4: Tag and push**

Run:
```bash
git tag v3.1.0 -m "v3.1.0"
git push origin v3.1.0
```
Expected: Tag created, pushed, `release.yml` workflow fires.

- [ ] **Step 5: Verify the release workflow built a zip**

Run: `gh run list --workflow=release.yml --limit=1 --json status,conclusion,url` (wait until conclusion is `success`)
Then: `gh release view v3.1.0 --json assets --jq '.assets[].name'`
Expected: `ucp-for-woocommerce-3.1.0.zip` (or similar) attached to the release.

If the build failed, read the logs (`gh run view --log-failed`), fix root cause, re-tag with a v3.1.0a or fix-and-re-tag depending on what failed.

---

## Phase 1: Create v3.1.1 readiness branch

### Task 1.1: Branch off main

**Files:**
- Read-only

- [ ] **Step 1: Confirm clean tree on main**

Run: `git status --short && git rev-parse --abbrev-ref HEAD`
Expected: empty output for status, `main` for branch.

- [ ] **Step 2: Pull main**

Run: `git pull --ff-only`
Expected: Already up to date (we just merged).

- [ ] **Step 3: Create the branch**

Run: `git checkout -b feat/wporg-submission-readiness`
Expected: New branch created.

### Task 1.2: Set up audit notes file

**Files:**
- Create: `docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md`

- [ ] **Step 1: Create the findings file**

Write `docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md`:

```markdown
# WP.org Submission Readiness — Audit Findings

This file is appended to during Phase 2 audit tasks and consumed during Phase 3 fix tasks. Excluded from dist via `.distignore`'s `*.md` rule.

| ID | Severity | File:Line | Finding | Fix task |
| --- | --- | --- | --- | --- |
```

- [ ] **Step 2: Commit the scaffolding**

Run:
```bash
git add docs/
git commit -m "chore: scaffold WP.org submission readiness plan + findings"
```

---

## Phase 2: Strict code re-audit (file-by-file against WP.org guidelines)

**Audit checklist** — apply each item to every file in the bundle:

| # | Concern | What to look for |
| --- | --- | --- |
| C1 | **Sanitization on input** | `$_GET`/`$_POST`/`$_REQUEST`/`$_SERVER`/REST params: `sanitize_text_field`, `absint`, `wp_unslash`, etc. before any use. |
| C2 | **Escaping on output** | All user-visible HTML wrapped in `esc_html`/`esc_attr`/`esc_url`/`wp_kses_*` at the print site. |
| C3 | **Capability check on writes** | Every admin action that mutates state calls `current_user_can('manage_woocommerce')` (or stronger). REST writes have a `permission_callback` that does the same — never `__return_true` on a write endpoint. |
| C4 | **Nonce on writes** | Every admin form / AJAX / REST write verifies a nonce (`wp_verify_nonce`, `check_admin_referer`, `check_ajax_referer`, or `WP_REST_Server`'s nonce path). |
| C5 | **Prepared statements** | Every direct `$wpdb` query uses `$wpdb->prepare()` with `%s/%d/%f` placeholders. Table names interpolated only from `$wpdb->prefix . 'ucp_*'` constants, never from user input. |
| C6 | **No remote code execution** | No `eval`, no `create_function`, no `assert($string)`, no `include`/`require` of remote URLs, no `base64_decode` → `eval` chain. |
| C7 | **No call-home on activation** | `register_activation_hook` callback does NOT make outbound HTTP. (Per readme claim of zero outbound traffic without a license.) |
| C8 | **Tier separation** | Tier 1 (`includes/core/`) makes zero outbound HTTP to `*.shopwalk.com`. Outbound calls allowed only to: agent-configured webhook URLs, Stripe, self-loopback for diagnostics. |
| C9 | **i18n** | Every user-visible string wrapped in `__`/`_e`/`esc_html__` etc. with the `'ucp-for-woocommerce'` text domain. No string concatenation that breaks translator context. |
| C10 | **Secret storage** | OAuth client secrets, license keys, webhook secrets stored hashed (`password_hash`/`bcrypt`) where they only need to be verified, not redisplayed. Never logged. Never echoed in admin UI. |
| C11 | **No SSRF on outbound URLs** | Any HTTP call to a URL that came from a request must validate the URL's scheme is `https`, host is not internal/loopback, and apply a request timeout. |
| C12 | **Output buffering / no premature output** | No `echo`/`print`/whitespace-before-`<?php` that fires before headers are sent (breaks redirects, cookies). |
| C13 | **Properly enqueued scripts/styles** | All admin JS/CSS goes through `wp_enqueue_script`/`wp_enqueue_style` with versioned handles, not inline `<script>`/`<link>` in HTML output. |
| C14 | **Uninstall completeness** | `uninstall.php` drops every table and option this plugin creates. Cross-check against `dbDelta` callsites and `update_option` callsites. |
| C15 | **Activation idempotency** | Re-running the activation callback (e.g. after deactivate-reactivate) doesn't duplicate options, schedule duplicate cron events, or fail on existing files. |

**For each finding, append a row to `findings.md` with:**
- ID: `F-<bundle>-<n>` (e.g. `F-A-1`, `F-B-3`)
- Severity: `BLOCKER` (must fix for submission), `MAJOR` (should fix), `MINOR` (nice-to-have)
- File:Line, Finding, Fix task pointer (filled in Phase 3)

### Task 2.A: Audit Bundle A — Admin pages

**Files (read-only audit):**
- `includes/admin/class-dashboard.php`
- `includes/admin/class-self-test.php`
- `includes/shopwalk/class-shopwalk-dashboard-panel.php`
- `includes/shopwalk/class-shopwalk-connect.php` (admin-side OAuth-style connect handler)

**Priority concerns for this bundle:** C1, C2, C3, C4, C9, C12, C13.

- [ ] **Step 1: Read each file end-to-end**

Run: `wc -l includes/admin/*.php includes/shopwalk/class-shopwalk-dashboard-panel.php includes/shopwalk/class-shopwalk-connect.php`
Then read each file in full (do not skim) and apply the checklist.

- [ ] **Step 2: Grep for high-signal patterns within the bundle**

```bash
grep -nE 'echo |print | _e\(' includes/admin/*.php includes/shopwalk/class-shopwalk-dashboard-panel.php includes/shopwalk/class-shopwalk-connect.php | head -50
grep -nE '\$_(GET|POST|REQUEST|SERVER)' includes/admin/*.php includes/shopwalk/class-shopwalk-dashboard-panel.php includes/shopwalk/class-shopwalk-connect.php
grep -nE 'current_user_can|check_admin_referer|wp_verify_nonce|check_ajax_referer' includes/admin/*.php includes/shopwalk/class-shopwalk-dashboard-panel.php includes/shopwalk/class-shopwalk-connect.php
grep -nE 'wp_enqueue_(script|style)|<script|<link rel' includes/admin/*.php includes/shopwalk/class-shopwalk-dashboard-panel.php includes/shopwalk/class-shopwalk-connect.php
```

For each `$_(GET|POST|...)` hit, confirm the value is sanitized AND a nonce was verified before that line in the same handler. Each `echo`/`print` site, confirm escaped.

- [ ] **Step 3: Append findings to `findings.md`**

For each issue, add a row. Even if zero findings, add a row noting "Bundle A audit complete, no findings."

- [ ] **Step 4: Commit findings**

Run:
```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle A (admin pages) findings"
```

### Task 2.B: Audit Bundle B — REST surface & permission_callbacks

**Files (read-only audit):**
- `includes/core/class-ucp-discovery.php`
- `includes/core/class-ucp-products.php`
- `includes/core/class-ucp-orders.php`
- `includes/core/class-ucp-checkout.php`
- `includes/core/class-ucp-direct-checkout.php`
- `includes/core/class-ucp-webhook-subscriptions.php`
- `includes/core/class-ucp-response.php`

**Priority concerns:** C1, C3, C4 (REST nonce path), C5, C8, C11.

- [ ] **Step 1: Inventory every `register_rest_route` call**

Run: `grep -nE "register_rest_route" includes/core/*.php`
For each, capture: route, methods, `permission_callback`, `args` schema. Confirm permission_callback is appropriate for the method (write methods MUST require auth — OAuth bearer for buyer-scoped routes, capability for admin routes).

- [ ] **Step 2: Read each file in the bundle, applying C1, C5, C8, C11**

Specifically: `class-ucp-direct-checkout.php:359` — confirm the `wp_remote_post` target is the agent-configured callback or per-checkout URL, NOT `api.shopwalk.com`. If it goes to Shopwalk regardless of license state, that's a **BLOCKER** (contradicts readme.txt:42, 70-72).

- [ ] **Step 3: Append findings**

Add per-finding rows to `findings.md`.

- [ ] **Step 4: Commit findings**

```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle B (REST surface) findings"
```

### Task 2.C: Audit Bundle C — OAuth + token security

**Files:**
- `includes/core/class-ucp-oauth-server.php`
- `includes/core/class-ucp-oauth-clients.php`
- `includes/core/class-ucp-signing.php`

**Priority concerns:** C5, C6, C10, plus OAuth-specifics:
- O1 — Authorization code is single-use and expires (≤10 min).
- O2 — Refresh tokens rotate on use.
- O3 — Access tokens are bearer-style; storage is hashed.
- O4 — PKCE enforced on `authorization_code` grant.
- O5 — `state` parameter required and validated.
- O6 — `redirect_uri` exact-match against registered client URIs (no prefix-match, no wildcards).
- O7 — Constant-time comparison for token verification (`hash_equals`).
- O8 — Client secret stored hashed.

- [ ] **Step 1: Read each file end-to-end**

- [ ] **Step 2: For each OAuth concern (O1-O8), find the line that enforces it or note its absence**

Append findings.

- [ ] **Step 3: Cross-check with `tests/PkceTest.php`**

Run: `cat tests/PkceTest.php`
Confirm test coverage for the PKCE enforcement path. If a security property has no test, note it (MAJOR).

- [ ] **Step 4: Commit findings**

```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle C (OAuth + token security) findings"
```

### Task 2.D: Audit Bundle D — Webhook delivery & signing

**Files:**
- `includes/core/class-ucp-webhook-delivery.php`
- `includes/core/class-ucp-webhook-subscriptions.php`
- `includes/core/class-ucp-signing.php` (re-read in this context)

**Priority concerns:** C5 (DB queries), C8 (no Shopwalk-only URLs), C11 (SSRF on subscriber URLs), and webhook-specifics:
- W1 — HMAC signature uses constant-time comparison on inbound verification (if any).
- W2 — Subscriber URL must be `https` and not localhost/internal IP at subscription time.
- W3 — Webhook payload doesn't leak secrets (no API keys, no full PII beyond what's needed).
- W4 — Retry/backoff is bounded (no infinite retry).
- W5 — Dead-letter handling exists and is admin-visible.

- [ ] **Step 1: Read each file end-to-end**

- [ ] **Step 2: For each W concern, find the line or note absence**

- [ ] **Step 3: Append findings + commit**

```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle D (webhook delivery) findings"
```

### Task 2.E: Audit Bundle E — Payment router + adapters

**Files:**
- `includes/core/class-ucp-payment-router.php`
- `includes/core/class-ucp-payment-adapter-stripe.php`
- `includes/core/class-ucp-payment-gateway.php`
- `includes/core/interface-ucp-payment-adapter.php`

**Priority concerns:** PCI-relevant — verify NO PAN, CVV, or full card data is logged or stored. Tokenized credentials only. The plugin must reuse the merchant's WC Stripe credentials (per readme.txt:80) and never read its own.

- [ ] **Step 1: Read each file end-to-end**

- [ ] **Step 2: Grep for any local credential storage**

```bash
grep -nE "stripe.*key|api_key|secret_key" includes/core/class-ucp-payment-*.php
```
Any hit must come from the merchant's existing WC Stripe option (`get_option('woocommerce_stripe_settings')` or similar), NEVER from the plugin's own settings.

- [ ] **Step 3: Confirm the adapter filter `shopwalk_ucp_payment_adapters` is documented and stable**

Per readme.txt:88, third parties can register adapters. Verify the contract.

- [ ] **Step 4: Append findings + commit**

```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle E (payment) findings"
```

### Task 2.F: Audit Bundle F — Shopwalk Tier 2 integration

**Files:**
- `includes/shopwalk/class-shopwalk-license.php`
- `includes/shopwalk/class-shopwalk-connector.php`
- `includes/shopwalk/class-shopwalk-sync.php`

**Priority concerns:** Verify Tier 2 only fires when `Shopwalk_License::is_connected()` returns true. Disclosure in readme.txt § "External Services" (lines 94-108) must accurately describe every field actually sent. Cross-reference the `wp_remote_post` payload construction in `class-shopwalk-sync.php` against the readme list.

- [ ] **Step 1: Read each file end-to-end**

- [ ] **Step 2: Build the actual outbound field list**

Find the place in `class-shopwalk-sync.php` where the product payload is assembled. Enumerate every key sent. Compare to readme.txt:98-103.

If readme.txt understates the disclosure (we send something not listed), that's a **BLOCKER** for WP.org review.
If readme.txt overstates (lists something we don't send), update the readme.

- [ ] **Step 3: Confirm Tier 2 gate**

For each outbound HTTP call site under `includes/shopwalk/`, find the `is_connected()` (or equivalent) check that gates it. Document the path.

- [ ] **Step 4: Append findings + commit**

```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle F (Shopwalk Tier 2) findings"
```

### Task 2.G: Audit Bundle G — Activation, deactivation, uninstall

**Files:**
- `includes/class-woocommerce-ucp.php` (activate/deactivate static methods + the `register_*_hook` callbacks)
- `uninstall.php`
- `includes/core/class-ucp-bootstrap.php`
- `includes/core/class-ucp-storage.php` (dbDelta callsites)

**Priority concerns:** C7 (no activation call-home), C14 (uninstall completeness), C15 (idempotency).

- [ ] **Step 1: Confirm activation hook makes zero outbound HTTP**

Run: `grep -nE "wp_remote_|file_get_contents.*http|curl_" includes/class-woocommerce-ucp.php`
Cross-check any hits against the call stack — they must NOT be reachable from `register_activation_hook`'s callback.

- [ ] **Step 2: Inventory tables created**

Run: `grep -rn "dbDelta\|CREATE TABLE" includes/`
List every table. Then verify each one is dropped in `uninstall.php`.

- [ ] **Step 3: Inventory options written**

Run: `grep -rnE "update_option\(\s*['\"]?(shopwalk|ucp|woocommerce_ucp)" includes/`
List every option key. Verify each is removed in `uninstall.php`.

- [ ] **Step 4: Inventory cron events scheduled**

Run: `grep -rnE "wp_schedule_event|wp_schedule_single_event" includes/`
List every hook. Verify each is unscheduled in deactivate AND in `uninstall.php`.

- [ ] **Step 5: Append findings + commit**

```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle G (activation/uninstall) findings"
```

### Task 2.H: Audit Bundle H — CLI + bootstrap glue

**Files:**
- `includes/core/class-ucp-cli.php`
- `includes/core/class-ucp-self-test.php`
- `includes/core/class-ucp-store.php`
- `includes/core/class-ucp-sync-trigger.php`

**Priority concerns:** C5, C9. CLI commands should require admin privilege at the OS level (assumed via WP-CLI invocation), but any UI-driven trigger must check capabilities.

- [ ] **Step 1: Read each file end-to-end**

- [ ] **Step 2: Append findings + commit**

```bash
git add docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
git commit -m "audit: bundle H (CLI + glue) findings"
```

### Task 2.Z: Audit summary review

- [ ] **Step 1: Read the complete findings file**

Read `docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md` in full.

- [ ] **Step 2: Tally**

Count BLOCKER, MAJOR, MINOR severity findings. Write a one-line summary at the top of the file:

```markdown
**Summary (filled at end of Phase 2):** N blockers, M majors, K minors. See Phase 3 for fix tasks.
```

- [ ] **Step 3: Stop and present to user**

If any BLOCKER is in scope, escalate to user before proceeding to Phase 3 — they may want to discuss approach.

If only MAJOR/MINOR, proceed to Phase 3.

---

## Phase 3: Fix gaps

This phase contains the four already-known fix tasks. After Phase 2 completes, append additional fix tasks here, one per finding (or grouped by file when fixes co-locate). Use the same TDD-or-grep-verify pattern: change → verify → commit.

### Task 3.1: Fix `→` Unicode escape in screenshot captions

**Files:**
- Modify: `readme.txt:112-114`

- [ ] **Step 1: Read the current state**

Run: `sed -n '110,116p' readme.txt`
Expected output includes literal `→` strings.

- [ ] **Step 2: Replace with the actual `→` character**

Edit `readme.txt`, replace each `→` with `→` (U+2192 RIGHTWARDS ARROW). Three occurrences in lines 112, 114, 115.

- [ ] **Step 3: Verify no `\u` escapes remain**

Run: `grep -n '\\\\u[0-9a-fA-F]\\{4\\}' readme.txt`
Expected: empty output.

- [ ] **Step 4: Commit**

```bash
git add readme.txt
git commit -m "fix(readme): replace literal \\u2192 escapes with → arrow in screenshot captions"
```

### Task 3.2: Fix PHP version drift in FAQ prose

**Files:**
- Modify: `readme.txt:76`

- [ ] **Step 1: Read the current state**

Run: `sed -n '74,78p' readme.txt`
Expected: line 76 says "PHP 8.0 or later".

- [ ] **Step 2: Update the prose to match the header**

Edit `readme.txt:76`:
- Old: `WooCommerce 8.0 or later. WordPress 6.0 or later. PHP 8.0 or later.`
- New: `WooCommerce 8.0 or later. WordPress 6.0 or later. PHP 8.1 or later.`

- [ ] **Step 3: Confirm consistency across header + prose + plugin file**

Run: `grep -nE "PHP\s*8\." readme.txt ucp-for-woocommerce.php`
Expected: every match says `8.1`, not `8.0`.

- [ ] **Step 4: Commit**

```bash
git add readme.txt
git commit -m "fix(readme): align FAQ PHP requirement (8.0 → 8.1) with header"
```

### Task 3.3: Verify `Tested up to:` against current stable WordPress

**Files:**
- Possibly modify: `readme.txt:5`

- [ ] **Step 1: Get the current stable WP version**

Run: `curl -s https://api.wordpress.org/core/version-check/1.7/ | grep -oE '"current":"[^"]+"' | head -1`
Expected: a version string like `"current":"6.9.x"`.

- [ ] **Step 2: Compare to `readme.txt:5`**

Run: `grep "^Tested up to:" readme.txt`

If the major.minor of the API response is higher than what `readme.txt` shows, update `Tested up to:` to match.

- [ ] **Step 3: If updated, run the test suite against the new value**

Run: `composer test` (or `vendor/bin/phpunit`)
Expected: all tests pass.

- [ ] **Step 4: Commit (only if changed)**

```bash
git add readme.txt
git commit -m "chore(readme): bump Tested up to: <X.Y> (current stable WP)"
```

### Task 3.4: Regenerate POT file

**Files:**
- Modify: `languages/ucp-for-woocommerce.pot`

- [ ] **Step 1: Confirm wp-cli + wp-i18n are available**

Run: `wp --info 2>&1 | head -3`
Expected: a wp-cli version. If not, install via the user's preferred method (probably `composer global`).

Run: `wp package list 2>&1 | grep i18n`
Expected: `wp-cli/i18n-command` listed. Install with `wp package install wp-cli/i18n-command` if missing.

- [ ] **Step 2: Regenerate the POT**

Run:
```bash
wp i18n make-pot . languages/ucp-for-woocommerce.pot \
    --domain=ucp-for-woocommerce \
    --exclude=vendor,node_modules,tests,assets,languages,docs
```
Expected: `Plugin file detected.` then `Success: POT file successfully generated!`

- [ ] **Step 3: Diff the result**

Run: `git diff --stat languages/`
Expected: changes are confined to the POT file.

- [ ] **Step 4: Commit only if non-trivial diff**

If the only diff is the timestamp header, skip the commit (no real changes). Otherwise:

```bash
git add languages/ucp-for-woocommerce.pot
git commit -m "chore(i18n): regenerate POT for v3.1.1 readiness"
```

### Task 3.N: Fix tasks generated from Phase 2 findings

For each finding row in `findings.md`, write a Task 3.N with:
- Severity-driven priority (BLOCKERS first)
- Exact file:line
- Failing test if the issue is testable; otherwise grep-based verify
- Minimal fix
- Re-run test or grep
- Commit, referencing the finding ID

Append these tasks to this plan document under this section before starting Phase 3 fixes.

---

## Phase 4: Customer-path end-to-end test on shopwalkstore.com / shop1

**Context:** Per the "Test the customer path" rule, every assertion below must be exercised through the actual UI/REST surface, NOT via WP-CLI/SQL/admin shortcuts. WP-CLI is allowed only for environment setup (installing the plugin zip, activating WC) — once setup is done, test as the merchant or agent does.

**Test environment:** shopwalkstore.com on shop1 (test account, no real customers).

**Pre-requisites the user must confirm before starting:**
- Fresh WP install (or a clean baseline state).
- WooCommerce active.
- WC Stripe gateway configured with test keys.
- A few test products (at least one Simple, one Variable with 2+ variations).
- Access to install plugins via WP Admin → Plugins → Add New → Upload.

### Task 4.1: Install v3.1.1 zip on shop1

**Files:**
- None (UI-driven)

- [ ] **Step 1: Build the v3.1.1 zip locally** (after Phase 3 completes)

The `release.yml` workflow needs a tag — but we don't tag until everything's verified. So for testing, build the zip manually:

Run:
```bash
mkdir -p /tmp/ucp-build && rm -rf /tmp/ucp-build/*
rsync -a --exclude-from=.distignore . /tmp/ucp-build/ucp-for-woocommerce/
cd /tmp/ucp-build && zip -r ucp-for-woocommerce-3.1.1-rc.zip ucp-for-woocommerce
ls -lh ucp-for-woocommerce-3.1.1-rc.zip
```

- [ ] **Step 2: User uploads the zip**

User: WP Admin → Plugins → Add New → Upload Plugin → choose `ucp-for-woocommerce-3.1.1-rc.zip` → Install Now.
Expected: "Plugin installed successfully."

- [ ] **Step 3: Activate**

User: Activate the plugin.
Expected: No PHP warnings/notices in the Plugins screen, no fatal errors. Plugin appears in the WP Admin sidebar as "UCP".

- [ ] **Step 4: User reports back**

Pause and have user paste any warnings/notices observed. If anything fires, fix before proceeding.

### Task 4.2: Run the self-test diagnostic

- [ ] **Step 1: User clicks Run self-test in the dashboard**

Path: WP Admin → UCP → Run self-test.
Expected: All 8 checks pass (green).

- [ ] **Step 2: User pastes the result list**

If any check fails, capture the failure message verbatim, fix root cause, rebuild zip, redeploy, re-run.

### Task 4.3: Verify `.well-known` discovery docs

- [ ] **Step 1: Curl `.well-known/ucp`**

Run from anywhere: `curl -sI https://shopwalkstore.com/.well-known/ucp` and `curl -s https://shopwalkstore.com/.well-known/ucp | head -20`
Expected: `200 OK`, `Content-Type: application/json`, body is valid JSON with at least `{"version", "endpoints", ...}`.

- [ ] **Step 2: Curl OAuth metadata**

Run: `curl -s https://shopwalkstore.com/.well-known/oauth-authorization-server | jq .`
Expected: valid RFC 8414 metadata document with `authorization_endpoint`, `token_endpoint`, `revocation_endpoint`, `userinfo_endpoint` URLs all under the same origin.

- [ ] **Step 3: Validate JSON shape**

Pipe the body to `jq -e` with the expected required keys. Any failure = blocker.

### Task 4.4: Drive a real OAuth flow against the live store

**As an agent (use curl + a browser for the redirect step):**

- [ ] **Step 1: Confirm or create a test OAuth client**

If the dashboard exposes "Register a test OAuth client", use it. Otherwise the plugin's WP-CLI command (`wp ucp oauth-clients create`) is OK here because client registration is a one-time setup, not a customer action.

Capture: `client_id`, `client_secret`, `redirect_uri`.

- [ ] **Step 2: Build the authorize URL with PKCE**

Generate a `code_verifier` (43+ char random) and `code_challenge` (base64url(sha256(verifier))).
Build: `https://shopwalkstore.com/wp-json/ucp/v1/oauth/authorize?response_type=code&client_id=...&redirect_uri=...&state=xyz&code_challenge=...&code_challenge_method=S256`
Open in browser. Log in as a WP user (test account). Approve.
Capture the `code` from the redirect.

- [ ] **Step 3: Exchange code for tokens**

```bash
curl -X POST https://shopwalkstore.com/wp-json/ucp/v1/oauth/token \
  -d "grant_type=authorization_code" \
  -d "code=..." \
  -d "redirect_uri=..." \
  -d "client_id=..." \
  -d "code_verifier=..."
```
Expected: JSON `{"access_token", "refresh_token", "token_type":"Bearer", "expires_in"}`.

- [ ] **Step 4: Call userinfo with the token**

```bash
curl -H "Authorization: Bearer <access_token>" https://shopwalkstore.com/wp-json/ucp/v1/oauth/userinfo
```
Expected: JSON profile of the logged-in WP user.

- [ ] **Step 5: Verify refresh token rotates**

Use the refresh token to get a new access token. Try the OLD refresh token again — should fail.

- [ ] **Step 6: Verify revoke**

Call `oauth/revoke` with the access token. Re-call userinfo with it. Should fail with 401.

Each step that fails → file as a Phase 3 fix task, fix, rebuild zip, redeploy, retry.

### Task 4.5: Drive a UCP checkout end-to-end

- [ ] **Step 1: Pick a real WC product and create a checkout session**

```bash
curl -X POST https://shopwalkstore.com/wp-json/ucp/v1/checkout-sessions \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"sku":"<sku>","quantity":1}], "shipping_address":{...}, "buyer":{...}}'
```
Expected: JSON session with id, totals, available payment methods.

- [ ] **Step 2: Update the session (e.g. add a second item)**

PATCH the session. Confirm totals recalc.

- [ ] **Step 3: Complete with a Stripe test token**

Use Stripe's `tok_visa` or a tokenized PaymentMethod from Stripe Elements (test mode).
POST `/checkout-sessions/<id>/complete` with the token.
Expected: JSON response with order_id, status, and (per readme.txt:84) either an authorized order or a `payment_url` if 3DS is required.

- [ ] **Step 4: Verify the WooCommerce order was created**

User: WP Admin → WooCommerce → Orders. Find the order. Confirm:
- Status is `processing` (or as expected per gateway).
- Line items match the session.
- Customer matches the OAuth-authenticated user.
- Payment method shows Stripe (or whichever adapter handled it).

- [ ] **Step 5: Test variable product checkout (variations work from v3.1.0)**

Repeat steps 1-4 with a variable product, supplying `variant_id` from the variations[] array returned by `GET /products`.
Expected: order created with the correct variation.

### Task 4.6: Verify webhook delivery

- [ ] **Step 1: Set up a webhook subscription pointing at a sink**

Use webhook.site or a similar throwaway endpoint to capture incoming POSTs.

```bash
curl -X POST https://shopwalkstore.com/wp-json/ucp/v1/webhooks/subscriptions \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://webhook.site/<id>","events":["order.created","order.updated"]}'
```

- [ ] **Step 2: Trigger an event by completing another checkout (Task 4.5 redux)**

- [ ] **Step 3: Confirm the webhook arrived at the sink**

Check webhook.site for the POST. Verify:
- Body is JSON with the event payload.
- `Content-Digest` header present and matches sha256(body).
- HMAC `Signature` header present.
- HMAC verifies against the subscription secret.

- [ ] **Step 4: Test retry / dead-letter**

Subscribe with a URL that returns 500 (httpstat.us/500). Trigger an event. Confirm:
- Plugin retries with exponential backoff (check WP Admin → UCP for queue/dead-letter visibility).
- After max retries, message lands in dead-letter and is admin-visible.

### Task 4.7: Connect to Shopwalk (Tier 2 opt-in)

- [ ] **Step 1: Verify zero outbound traffic to Shopwalk before connect**

From shop1, tail PHP error log + access log for any `api.shopwalk.com` egress before the connect button is clicked. (User: SSH to shop1 and `tcpdump -i any -nn port 443 and host api.shopwalk.com` for ~60s while the plugin is installed but unconnected and the dashboard is loaded.)
Expected: zero packets.

- [ ] **Step 2: Click "Connect to Shopwalk" in the dashboard**

User: WP Admin → UCP → Shopwalk panel → Connect.
Drive the OAuth-style connect flow to completion (per the plugin's existing pattern — uses `Shopwalk_License::activate()`).
Expected: dashboard now shows "Connected" with plan label.

- [ ] **Step 3: Verify product sync starts**

Wait ~2 minutes (or click "Sync Now" if exposed). User: WP Admin → UCP → check sync queue depth.
Expected: products begin enqueueing and draining.

- [ ] **Step 4: Verify products appear in shopwalk-api**

Cross-check on the shopwalk-api side — query the partner's products via the partner portal or an admin tool. Expected: products from shop1 visible.

### Task 4.8: Test "Pause discovery" toggle (v3.0.47 feature)

- [ ] **Step 1: Toggle pause ON in dashboard**

Expected: dashboard reflects paused state. Subsequent sync events should not advance.

- [ ] **Step 2: Verify on shopwalk-api side**

Query partner status — `discovery_paused: true`. Products no longer appear in agent search results for this partner.

- [ ] **Step 3: Toggle pause OFF**

Expected: state flips back, sync resumes.

### Task 4.9: Test deactivate

- [ ] **Step 1: Deactivate the plugin**

User: WP Admin → Plugins → UCP for WooCommerce → Deactivate.
Expected: no error.

- [ ] **Step 2: Verify cron jobs unscheduled**

User runs `wp cron event list | grep -E "shopwalk|ucp"` (CLI for diagnostic only).
Expected: no rows.

- [ ] **Step 3: Verify .well-known files cleaned**

Run: `curl -sI https://shopwalkstore.com/.well-known/ucp`
Expected: 404 (the static shim file should be removed on deactivate).

- [ ] **Step 4: Reactivate**

Confirm idempotency — `.well-known` files re-created, crons rescheduled, no duplicate options.

### Task 4.10: Test uninstall

- [ ] **Step 1: Deactivate, then Delete from the Plugins screen**

User: WP Admin → Plugins → UCP for WooCommerce → Delete.
Expected: confirmation dialog, then plugin removed.

- [ ] **Step 2: Verify all `wp_ucp_*` tables dropped**

User: `wp db query "SHOW TABLES LIKE 'wp_ucp_%'"`
Expected: empty result.

- [ ] **Step 3: Verify all `shopwalk_*` and `woocommerce_ucp_*` options removed**

User: `wp option list --search='shopwalk_*' --format=count` and `wp option list --search='woocommerce_ucp_*' --format=count`
Expected: `0` for both.

- [ ] **Step 4: Verify WC data untouched**

User: WP Admin → WooCommerce → Orders. Orders created during testing should still be present.

### Task 4.Z: Customer-path test summary

- [ ] **Step 1: Document results**

Append a `## Customer-path test results` section to `findings.md` listing every Task 4.x step's outcome (pass/fail/skipped + why).

- [ ] **Step 2: Any failure escalates**

If any step failed and wasn't fixed in Phase 3, do NOT proceed to Phase 5/6. Loop back: file fix tasks, fix, rebuild zip, redeploy, retest the affected path.

---

## Phase 5: Final pre-flight per RELEASE.md

### Task 5.1: Re-run all CI locally + Plugin Check

- [ ] **Step 1: PHPCS**

Run: `composer phpcs` (or `vendor/bin/phpcs`)
Expected: 0 errors. Warnings only on the documented `phpcs:ignore` lines per RELEASE.md table.

- [ ] **Step 2: PHPUnit**

Run: `composer test`
Expected: all tests pass on PHP 8.1+.

- [ ] **Step 3: WP.org Plugin Check on the actual zip**

User: in shop1's WP Admin (or any local WP+WC), upload the v3.1.1-rc zip with `plugin-check` plugin active. Run Tools → Plugin Check → UCP for WooCommerce.
Expected: 0 errors. Warnings only on the documented items in RELEASE.md.

If Plugin Check finds new errors, fix and rebuild before tagging.

### Task 5.2: Verify the dist zip contents

- [ ] **Step 1: Build a fresh zip from current main+branch state**

Run: same rsync/zip from Task 4.1 step 1.

- [ ] **Step 2: List zip contents**

Run: `unzip -l /tmp/ucp-build/ucp-for-woocommerce-3.1.1-rc.zip`

Expected:
- Includes: `ucp-for-woocommerce.php`, `readme.txt`, `uninstall.php`, `includes/`, `assets/admin.css`, `assets/admin.js`, `assets/banner-*.png`, `assets/icon-*.png`, `languages/`.
- Excludes: `.git/`, `.github/`, `tests/`, `vendor/`, `composer.json`, `composer.lock`, `phpunit.xml`, `phpcs.xml`, `*.md` (except `readme.txt`), `docs/`.

- [ ] **Step 3: Confirm file count and total size are reasonable**

Run: `du -sh /tmp/ucp-build/ucp-for-woocommerce-3.1.1-rc.zip`
Expected: well under WP.org's 10MB limit.

### Task 5.3: Open PR for v3.1.1 and merge

- [ ] **Step 1: Push branch and open PR**

Run: `git push -u origin feat/wporg-submission-readiness && gh pr create --title "v3.1.1 — WP.org submission readiness" --body "$(cat <<'EOF'
## Summary
- Audit fixes per docs/superpowers/plans/2026-05-03-wporg-submission-readiness-findings.md
- Fix Unicode escape in screenshot captions
- Align FAQ PHP version with header
- Bump Tested up to: <X.Y> (if applicable)
- Regenerate POT
- Customer-path E2E verified on shopwalkstore.com

## Test plan
- [x] PHPCS green
- [x] PHPUnit 8.1/8.2/8.3 green
- [x] WP.org Plugin Check green
- [x] Customer-path test on shop1 — every step in plan Phase 4 passed
EOF
)"`

- [ ] **Step 2: Wait for CI green, merge**

Run: `gh pr checks <PR>` until SUCCESS, then `gh pr merge <PR> --squash --delete-branch`.

- [ ] **Step 3: Sync local main**

Run: `git checkout main && git pull --ff-only`

### Task 5.4: Tag v3.1.1 and confirm release zip built

- [ ] **Step 1: Bump version in same commit was done in Phase 3**

Verify: `grep -E "Version:|Stable tag:|WOOCOMMERCE_UCP_VERSION" ucp-for-woocommerce.php readme.txt`
Expected: every line shows `3.1.1`. (If not, this should have been a Phase 3 task — fix it on a quick PR before tagging.)

- [ ] **Step 2: Tag**

```bash
git tag v3.1.1 -m "v3.1.1"
git push origin v3.1.1
```

- [ ] **Step 3: Wait for the release workflow**

`gh run list --workflow=release.yml --limit=1` until success. Verify the asset:
`gh release view v3.1.1 --json assets --jq '.assets[].name'` should show the v3.1.1 zip.

---

## Phase 6: WP.org submission

### Task 6.1: Confirm screenshots are ready (user-produced on shop1)

**Files:**
- `assets/screenshot-1.png` … `assets/screenshot-4.png` (NOT in repo, they go directly to SVN /assets/ later — but the user produces them now)

- [ ] **Step 1: User captures the 4 screenshots from shopwalkstore.com / shop1**

Per readme.txt:112-115 captions:
1. WooCommerce → UCP dashboard with self-test panel + Shopwalk connection card.
2. Self-test diagnostic results showing 8 automated checks.
3. Payments section — "Pay via UCP" gateway visible in WC → Settings → Payments.
4. Optional Shopwalk connect flow.

Naming: `screenshot-1.png`, `screenshot-2.png`, `screenshot-3.png`, `screenshot-4.png`. PNG, ≥1280px wide recommended.

- [ ] **Step 2: User stores them somewhere we can grab for SVN upload later**

Example: `/home/jbushman/Pictures/wcucp-screenshots/`. Confirm path with user; do NOT commit them to the GitHub repo (they're SVN-only assets).

- [ ] **Step 3: Mark this task complete only after user confirms files exist**

### Task 6.2: Submit to WordPress.org

- [ ] **Step 1: Confirm WP.org account**

User: ensure the `shopwalkinc` account (or whichever org account will own the plugin — matches `Contributors:` in `readme.txt:2`) is logged in at https://wordpress.org/plugins/.

- [ ] **Step 2: Upload v3.1.1 zip**

User: https://wordpress.org/plugins/developers/add/ → Choose file → upload `ucp-for-woocommerce-3.1.1.zip` from the GitHub Release.

Submission notes (paste verbatim):
> Source repository: https://github.com/shopwalk-inc/ucp-for-woocommerce
> Source of truth is wordpress.org going forward; the GitHub repo is the dev mirror.
> The `WP.org Plugin Check` workflow runs on every PR and is green for this submission.
> Three documented `phpcs:ignore` patterns retained — rationale in `RELEASE.md` and a reviewer-facing summary in this submission's plain-text response below.

(Then paste the reviewer-story table from RELEASE.md.)

- [ ] **Step 3: Watch for the auto-acknowledgement email**

Expected: within minutes, an email from `plugins@wordpress.org` confirming receipt and giving an ETA (typically 1-14 days).

- [ ] **Step 4: Review queue wait**

Plan: when reviewer responds (approve or request-changes), reply within 1 business day. If changes are requested, file as Phase 3-style fix tasks, ship a v3.1.2 zip, reply with the new zip URL.

### Task 6.3: On approval — first SVN push

(Skip this until WP.org sends the SVN credentials email.)

- [ ] **Step 1: Check out SVN repo**

Run:
```bash
cd ~ && svn co https://plugins.svn.wordpress.org/ucp-for-woocommerce wp-svn-ucp-for-woocommerce
cd wp-svn-ucp-for-woocommerce
```

- [ ] **Step 2: Unpack the v3.1.1 GitHub Release zip into trunk/**

```bash
rm -rf trunk
unzip ~/Downloads/ucp-for-woocommerce-3.1.1.zip -d /tmp/wcucp
mv /tmp/wcucp/ucp-for-woocommerce trunk
svn add --force trunk
```

- [ ] **Step 3: Tag the version**

Run: `svn cp trunk tags/3.1.1`

- [ ] **Step 4: Add the screenshots + banner + icons to /assets/**

```bash
cp /home/jbushman/Pictures/wcucp-screenshots/screenshot-*.png assets/
cp ~/GIT/ucp-for-woocommerce/assets/banner-*.png assets/
cp ~/GIT/ucp-for-woocommerce/assets/icon-*.png assets/
svn add --force assets
```

- [ ] **Step 5: Commit**

Run: `svn commit -m "Initial release v3.1.1"`
Expected: SVN commit succeeds, plugin appears at `https://wordpress.org/plugins/ucp-for-woocommerce/` within minutes.

- [ ] **Step 6: Verify the listing**

User: open the plugin page. Confirm:
- Banner + icon render.
- All 4 screenshots show with captions.
- Version is 3.1.1.
- Description, FAQ, Changelog all render correctly.
- "Download" button serves the v3.1.1 zip.

---

## Self-Review (post-write)

**Spec coverage:** A.1 (submission readiness — Phases 0, 3, 5, 6), A.2 (customer-path testing — Phase 4), A.3 (strict re-audit — Phase 2), B.1 (land #46 first then branch — Phase 0 then Phase 1), C.1 (user takes screenshots on shop1 — Task 6.1), D (no customers — no slug-collision mitigation needed; not a phase). All four covered.

**Placeholder scan:** Phase 3 contains "Task 3.N: generated from findings" which is a placeholder by construction — that's correct. The instruction is "append after Phase 2 completes". No other placeholders.

**Type/identifier consistency:** Branch name `feat/wporg-submission-readiness` used consistently; zip name pattern `ucp-for-woocommerce-X.Y.Z[-rc].zip` consistent; findings file path consistent; `Shopwalk_License::is_connected()` matches what's in the codebase per CHANGELOG.md.

**Known scope decision deferred to user:** Phase 4 step "tcpdump on shop1 for outbound traffic" assumes user has SSH+root on shop1. If not, that single verification step needs an alternative (PHP debug log scan, or trust the audit findings instead). Surface this if user doesn't have SSH.
