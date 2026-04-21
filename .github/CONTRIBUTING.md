# Contributing to WooCommerce UCP

Thank you for your interest in contributing. This document covers everything you need to submit a great pull request.

---

## Code of Conduct

Be respectful. Constructive criticism is welcome; personal attacks are not. We reserve the right to close PRs or issues that violate this.

---

## What We Accept

- Bug fixes (with a linked issue)
- Compatibility improvements (new WP/WC versions, PHP versions)
- Performance improvements
- Translation files (`.po`/`.mo` for new languages)
- Documentation improvements

**What we generally don't accept without prior discussion:**

- New external API integrations
- New UCP endpoint implementations not in the official UCP spec
- Significant architectural changes
- Features that add optional premium functionality

Open an issue first if you're unsure — saves everyone time.

---

## Development Setup

**Requirements:** PHP 8.0+, Composer, a local WordPress + WooCommerce installation (Local by Flywheel, DDEV, or similar).

```bash
# Clone the repo
git clone https://github.com/shopwalk-inc/woocommerce-ucp.git
cd woocommerce-ucp

# Install dev dependencies (phpcs, qit-cli)
composer install

# Symlink or copy to your local WP install
ln -s $(pwd) /path/to/wp-content/plugins/woocommerce-ucp
```

---

## Coding Standards

This plugin follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

```bash
# Check for violations
./vendor/bin/phpcs --standard=WordPress .

# Auto-fix where possible
./vendor/bin/phpcbf --standard=WordPress .
```

All PRs must pass PHPCS with zero errors before merge.

---

## Branch Strategy

| Branch | Purpose |
|---|---|
| `main` | Stable, released code. WP.org/Woo Marketplace source. |
| `feature/your-feature` | New features. Branch from `main`. |
| `fix/issue-123` | Bug fixes. Branch from `main`. |

**All changes go through a pull request.** Direct pushes to `main` are blocked.

```bash
# Create a feature branch
git checkout main
git pull origin main
git checkout -b feature/my-improvement

# Make changes, commit with a descriptive message
git add .
git commit -m "feat: add support for product bundles in UCP catalog"

# Push and open a PR
git push origin feature/my-improvement
```

---

## Commit Message Format

Use the [Conventional Commits](https://www.conventionalcommits.org/) format:

```
<type>: <short description>

Optional longer body explaining why, not what.
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `security`

Examples:
```
feat: add stock reservation during UCP checkout sessions
fix: prevent duplicate sync on woocommerce_update_product for drafts
security: validate inbound API key with constant-time comparison
docs: update README with UCP endpoint reference table
```

---

## Pull Request Process

1. **Open an issue first** for non-trivial changes — describe the problem and proposed solution
2. **Branch from `main`**, not from another feature branch
3. **Keep PRs focused** — one feature or fix per PR
4. **Fill out the PR template** completely — incomplete PRs will be closed
5. **All CI checks must pass** before review
6. **At least one approving review** is required from a Shopwalk maintainer
7. **Resolve all review conversations** before merge
8. Maintainer will **squash merge** to keep `main` history clean

---

## Testing Checklist

Before submitting a PR, verify:

- [ ] Tested on latest WordPress (6.x) and WooCommerce (9.x)
- [ ] No PHP notices, warnings, or errors in debug mode (`WP_DEBUG=true`)
- [ ] PHPCS passes: `./vendor/bin/phpcs --standard=WordPress .`
- [ ] Plugin activates and deactivates cleanly
- [ ] Plugin uninstalls cleanly (all options removed)
- [ ] Sync still works if your change touches the sync class
- [ ] UCP endpoints still respond correctly if your change touches routing
- [ ] Settings save and load correctly

---

## Translations

Translation files go in `languages/woocommerce-ucp-{locale}.po`.

The text domain is `woocommerce-ucp`. All user-facing strings must use `__()`, `_e()`, `esc_html__()`, or equivalent with this text domain.

---

## Reporting Bugs

Open a [GitHub Issue](https://github.com/shopwalk-inc/woocommerce-ucp/issues) using the Bug Report template.

Include:
- WordPress version
- WooCommerce version
- PHP version
- Steps to reproduce
- Expected vs actual behavior
- Any relevant error messages from `wp-content/debug.log`

**Security vulnerabilities must not be reported as public issues.** See [SECURITY.md](SECURITY.md).

---

## License

By submitting a pull request, you agree that your contribution will be licensed under the [GPL-2.0-or-later](../LICENSE) license that covers this project.
