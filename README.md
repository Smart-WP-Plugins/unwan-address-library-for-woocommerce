# Unwan – Address Library for WooCommerce

A single, unified address book for WooCommerce — one saved-address library for checkout and My Account, with full Checkout Blocks support.

[Documentation](https://docs.smartwpplugins.com/unwan) · [Report an issue](https://github.com/Smart-WP-Plugins/unwan-address-library-for-woocommerce/issues)

## What it does

Most WooCommerce stores make customers manage two separate address lists — billing and shipping — and re-enter the same details every order. Unwan replaces that with one address book: every saved address is available everywhere, at checkout and in My Account, with no duplication.

- One combined address book: the WooCommerce billing default, shipping default, and unlimited shared additional addresses.
- Native Checkout Blocks integration via WooCommerce's Blocks registry and Store API, plus a matching classic-checkout experience.
- Lossless default-address swaps and automatic duplicate detection.
- Light, dark, and system color modes with a configurable accent color.
- A concise `--unwan-*` CSS variable API and stable BEM classes for theme developers.
- Developer hooks for every filterable behavior.
- No custom database tables, no tracking, no telemetry.

See [readme.txt](readme.txt) for the full WordPress.org-facing description, FAQ, and changelog.

## Requirements

| Requirement | Minimum |
| --- | --- |
| WordPress | 6.5 |
| PHP | 7.4 |
| WooCommerce | 8.2 |

## Installation

Install from the WordPress Plugin Directory (**Plugins → Add New Plugin**, search "Unwan"), or download a release zip and upload it via **Plugins → Add New Plugin → Upload Plugin**. See the [Installation](readme.txt) section of `readme.txt` for full steps, or the [Installation docs](https://docs.smartwpplugins.com/unwan/installation).

## Development

```sh
npm install
composer install

npm run start          # watch mode for JS/block assets
npm run build           # production build

npm run format
npm run lint:js
npm run lint:css
composer run lint:php   # PHPCS: WordPress-Extra + PHPCompatibilityWP

npm run plugin-zip      # build a WordPress.org-ready release zip
```

### Project layout

| Area | Files |
| --- | --- |
| Bootstrap | `unwan-for-woocommerce.php` |
| Persistence | `includes/AddressRepository.php` |
| Settings | `includes/Admin/Settings.php` |
| My Account | `includes/AccountController.php`, `templates/myaccount/` |
| Picker source | `src/unwan-address-picker.js` (compiled to `build/unwan-address-picker.js`) |
| Checkout Blocks | `includes/Checkout/BlocksController.php`, `includes/Checkout/BlocksIntegration.php`, `src/blocks/` |
| Classic checkout | `includes/Checkout/ClassicCheckout.php` |
| Uninstall | `uninstall.php` |

The `build/` directory contains compiled, minified JavaScript produced from `src/` via `@wordpress/scripts`/webpack — both are included in every release so the source is always available alongside the compiled output.

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

Developed and maintained by [SmartWP Plugins](https://smartwpplugins.com/).
