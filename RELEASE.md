# Release checklist — Shopwalk for WooCommerce

How to ship a new version, and (the bigger one) how to submit to
wordpress.org. Excluded from the plugin zip via `.distignore`.

---

## Every release (the short loop)

1. **Bump version in the same commit as the change** (per the long-standing
   rule — auto-version workflow was retired 2026-04-20):
   - `shopwalk-for-woocommerce.php` header `Version:`
   - `readme.txt` header `Stable tag:`
   - `readme.txt` `== Changelog ==` — add a new entry at the top
2. **Open PR**, wait for green CI (`PHPCS`, `PHPUnit (PHP 8.1/8.2/8.3)`).
3. **Merge** the PR (squash or merge — either is fine; release.yml fires on tag, not merge).
4. **Tag** the merge commit:
   ```bash
   git checkout main && git pull --ff-only
   git tag v<X.Y.Z> -m "v<X.Y.Z>"
   git push origin v<X.Y.Z>
   ```
5. The `Build and publish release zip` workflow will:
   - Verify the tag matches `Version:` and `Stable tag:` (fails the build if not)
   - Assemble the dist tree honouring `.distignore`
   - Attach the zip to a new GitHub Release at the same tag

That's the whole release loop for GitHub. The wordpress.org loop is below.

---

## First-time wordpress.org submission

Do this once, ever. After approval, every release goes through the SVN loop.

### Pre-flight (do all of these before submitting)

- [ ] `Tested up to:` in `readme.txt` matches the latest stable WP release.
- [ ] `Requires PHP:` in `readme.txt` and the plugin header agree, and CI
      actually tests them all.
- [ ] `languages/shopwalk-for-woocommerce.pot` is up to date:
      ```bash
      wp i18n make-pot . languages/shopwalk-for-woocommerce.pot \
          --domain=shopwalk-for-woocommerce \
          --exclude=vendor,node_modules,tests,assets,languages
      ```
- [ ] `assets/screenshot-1.png` … `screenshot-N.png` exist and match the
      captions in the `== Screenshots ==` section of `readme.txt`. WP.org
      shows these on the listing page.
- [ ] `assets/banner-1544x500.png`, `assets/banner-772x250.png`,
      `assets/icon-128.png`, `assets/icon-256.png` exist (already in
      the repo).
- [ ] Run **Plugin Check** locally — the *real* gate (see next section).

### Run Plugin Check (the WP.org auto-review tool)

WP.org runs this against every submission. It uses a stricter ruleset
than our local `phpcs.xml`, so it will surface issues our `phpcs:ignore`
hides. Better to see them now than during review.

**This now runs in CI** as the `WP.org Plugin Check` workflow on every
PR + push to `main` — see `.github/workflows/plugin-check.yml`. The
green/red badge on a PR is the canonical answer to "would this pass
WP.org review?". To run locally as a sanity check:

```bash
# In a local WP install with WooCommerce active:
wp plugin install plugin-check --activate
wp plugin install ./shopwalk-for-woocommerce.zip          # or upload via Plugins → Add New
# UI: Tools → Plugin Check → pick "Shopwalk for WooCommerce" → Check it!
# CLI:
wp plugin check shopwalk-for-woocommerce
```

Triage the report:
- **Errors** must be fixed before submission. No exceptions.
- **Warnings** that are intentional get a documented `phpcs:ignore` in
  the source AND a note here so we have a story for the reviewer.

Items currently expected to warn (with our story for each):

| Sniff | Why we keep it | Reviewer story |
| --- | --- | --- |
| `WordPress.WP.CronInterval` | 1-min tick on `shopwalk_ucp_minute`; needed so partner-portal "Sync Now" feels instant | Both consumers short-circuit when empty; the cron is idle when the queue is drained |
| `WordPress.PHP.NoSilencedErrors` (`@unlink`) | Standard WP pattern for "try to delete a scratch file; don't care if missing" | We could route through `WP_Filesystem` if reviewer asks; current pattern is in core too |
| `Generic.Files.OneObjectStructurePerFile` (`class-ucp-payment-router.php`) | Interface stays co-located with its only consumer | Happy to split if reviewer asks; functionally identical |

### Submit

1. Build the v<X.Y.Z> release zip (CI does this on tag push) and grab it
   from the GitHub Release page.
2. Submit at https://wordpress.org/plugins/developers/add/ — upload the
   zip, fill in the submission notes (mention this is a fresh submission,
   point at the GitHub repo for source).
3. Review queue is **1–14 days**. The reviewer will reply via email.
4. Address any feedback by pushing a new release (steps above), then
   reply to the review email with the new zip URL.
5. On approval, you'll get **SVN credentials** at
   `https://plugins.svn.wordpress.org/shopwalk-for-woocommerce/`.

---

## Subsequent releases (after wordpress.org approval)

```bash
# 1. Check out the SVN repo (one-time)
svn co https://plugins.svn.wordpress.org/shopwalk-for-woocommerce wp-svn
cd wp-svn

# 2. Pull the GitHub release zip for v<X.Y.Z> and unpack into trunk/
rm -rf trunk
unzip ~/Downloads/shopwalk-for-woocommerce-<X.Y.Z>.zip -d /tmp/wcucp
mv /tmp/wcucp/shopwalk-for-woocommerce trunk
svn add --force trunk

# 3. Add the same content as a new SVN tag
svn cp trunk tags/<X.Y.Z>

# 4. Update the marketing assets (banner / icon / screenshots) ONLY when
#    they actually change. WP.org reads /assets/ from SVN root, not trunk.
cp /path/to/new/banner-*.png /path/to/new/icon-*.png \
   /path/to/new/screenshot-*.png assets/
svn add --force assets

# 5. Commit
svn commit -m "Release <X.Y.Z>"
```

Critical:
- `trunk/readme.txt` `Stable tag:` is what users get installed. If it
  doesn't match a tag in `tags/`, *no one updates*.
- Screenshots, banners, icons live in **`/assets/`** at SVN root — NOT
  in trunk. They never go in the zip; they're only on the listing page.
- The auto-deploy GitHub Action that some plugins use isn't wired up
  here yet; first few releases are manual SVN.

---

## What to do if a reviewer asks for changes

- They reply by email with the requested changes. Don't argue style;
  fix what's reasonable.
- If they flag a `phpcs:ignore` we have, point them at the rationale
  in the table above.
- If they reject something we genuinely need (e.g. the 1-min cron),
  consider an alternative: e.g. drop the cron and trigger the queue
  drain via a transient flag inspected on every admin pageload.
- After fixing, ship a new patch version through the GitHub release
  loop and reply to the review email with the new zip URL.

---

## Why we don't auto-submit to SVN on tag

We could wire up the `wpcli/wp-cli` SVN action to push every GitHub tag
to SVN automatically. Holding off until:
1. We're past the first wordpress.org review (so the SVN repo exists).
2. The release loop has run cleanly a few times by hand and we know
   the rough edges.

Tracked separately — when we wire it, document here.
