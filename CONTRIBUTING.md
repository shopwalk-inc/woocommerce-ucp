# Contributing to the Shopwalk for WooCommerce Plugin

Thank you for your interest in contributing! This plugin implements the [Universal Commerce Protocol (UCP)](https://ucp.dev) for WooCommerce. Optional Shopwalk integration layers on top of the core UCP adapter.

## How to Contribute

1. **Fork** the repo and create a branch from `main`
2. **Make your changes** — follow the existing code style (WordPress PHP coding standards)
3. **Test** against WooCommerce 8.x+ and WordPress 6.x+
4. **Open a Pull Request** — describe what you changed and why
5. A maintainer will review and merge

## What We Accept

- Bug fixes
- Compatibility updates (new WooCommerce/WordPress versions)
- Performance improvements
- Documentation improvements

## What We Don't Accept (Yet)

- New payment gateways (UCP handles this at the platform level)
- UI changes to the WooCommerce checkout (controlled by the partner's theme)
- Features that require changes to the UCP spec

## Code Style

- Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- All functions prefixed with `shopwalk_` or inside a `Shopwalk_` class
- No external dependencies beyond Composer packages already in `vendor/`

## Reporting Bugs

Open a [GitHub Issue](https://github.com/shopwalk-inc/shopwalk-woocommerce/issues) with:
- WordPress version
- WooCommerce version  
- PHP version
- Steps to reproduce
- Expected vs actual behavior

## Security Vulnerabilities

Please **do not** open a public issue for security bugs. Email `security@shopwalk.com` instead.

## License

By contributing, you agree your contributions are licensed under [GPL-2.0](LICENSE).
