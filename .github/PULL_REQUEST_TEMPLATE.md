## Summary

<!-- What does this PR do? Why is this change needed? Link to any related issues. -->

Fixes #<!-- issue number, or "N/A" -->

---

## Type of Change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to change)
- [ ] Security fix
- [ ] Documentation update
- [ ] Dependency update

---

## Changes Made

<!-- List the specific files/classes/functions changed and what was done. -->

- 
- 

---

## Testing

**Tested on:**
- WordPress version: 
- WooCommerce version: 
- PHP version: 

**Test scenarios completed:**

- [ ] Plugin activates without errors
- [ ] Plugin deactivates without errors
- [ ] Plugin uninstalls cleanly (check `wp_options` for leftover rows)
- [ ] Sync works (product save → Shopwalk receives update)
- [ ] UCP endpoints respond correctly
- [ ] Settings save and load correctly
- [ ] No PHP notices/warnings with `WP_DEBUG=true`
- [ ] PHPCS passes: `./vendor/bin/phpcs --standard=WordPress .`

---

## External API / Data

- [ ] This PR does **not** introduce any new calls to external services
- [ ] OR: This PR calls `___________` because `___________`, and this is disclosed in `readme.txt`

---

## Breaking Changes

- [ ] No breaking changes
- [ ] OR: Describe the breaking change and migration path:

---

## Screenshots / Logs

<!-- Attach screenshots or log snippets if relevant (settings UI changes, sync logs, etc.) -->

---

## Checklist

- [ ] My code follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [ ] All user-facing strings use `__()` / `_e()` with the `woocommerce-ucp` text domain
- [ ] I have not introduced any obfuscated code
- [ ] I have read [CONTRIBUTING.md](.github/CONTRIBUTING.md)
