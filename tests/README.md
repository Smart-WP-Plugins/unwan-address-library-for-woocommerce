# Unwan test suite

Integration tests that run against a real WordPress install with WooCommerce
active, using the WordPress core PHPUnit library. They exercise
`AddressRepository`'s role-swap and duplicate-detection branching, the settings
and uninstall contracts, the My Account templates, the classic-checkout
adapter, and the Checkout Blocks surface.

Nothing here ships: `tests/**`, `scripts/**`, and `phpunit.xml.dist` are all
outside `package.json#distFiles` and excluded by `.distignore`.

## Requirements

- PHP 7.4+ with the `mysqli` extension (the suite is verified on PHP 8.3)
- A MySQL/MariaDB server you can create a throwaway database on
- `svn`, `curl`, and `unzip` for fetching the core test library
- WooCommerce checked out next to this plugin in `wp-content/plugins/`
- `composer install` (installs PHPUnit and the Yoast PHPUnit polyfills)

## One-time setup

```sh
composer install
bash scripts/install-wp-tests.sh wordpress_test root '' localhost 7.1
```

The last two arguments are the database host and the WordPress version; pass
`latest` to track whatever WordPress currently ships. The script writes the
core test library, a matching WordPress install, and a `wp-tests-config.php`
into `/tmp/wordpress-tests-lib` (override with `WP_TESTS_DIR`), then symlinks
this plugin and WooCommerce into it.

### Using Local's bundled MySQL

Local's sites ship a MySQL server but do not expose it on the PATH, and their
socket path is often too long for MySQL's ~107-character limit. Start an
isolated server on a short socket path instead:

```sh
MB="$HOME/Library/Application Support/Local/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin"
"$MB/mysqld" --initialize-insecure --datadir=/tmp/unwan-db
"$MB/mysqld" --datadir=/tmp/unwan-db --socket=/tmp/unwan.sock --port=33067 &
"$MB/mysql" --socket=/tmp/unwan.sock -u root -e 'CREATE DATABASE wordpress_test'
```

Then point the installer at that socket and skip database creation:

```sh
bash scripts/install-wp-tests.sh wordpress_test root '' 'localhost:/tmp/unwan.sock' 7.1 true
```

## Running

```sh
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer run test
```

Useful variations:

```sh
# Readable test names
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --testdox

# One file or one behavior
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --filter AddressRepositoryRoleTest
```

The suite prints the WordPress, WooCommerce, and PHP versions it actually ran
against, so a green run is evidence for the `Tested up to` headers in
`readme.txt`.

## Layout

| File | Covers |
| --- | --- |
| `tests/bootstrap.php` | Loads WooCommerce, then Unwan, then installs WooCommerce's tables |
| `tests/includes/UnwanTestCase.php` | Fresh repository and customer per test, address fixtures, option cleanup |
| `AddressRepositoryStorageTest` | Where addresses live, sanitization, address-book composition |
| `AddressRepositoryDuplicateTest` | Normalized duplicate detection across defaults and extras |
| `AddressRepositoryRoleTest` | Lossless default swaps, deletion guards, the extras limit |
| `AddressRepositoryCheckoutTest` | Checkout option shaping, formatting, request-cache invalidation |
| `HooksTest` | Every filter and action in CLAUDE.md's developer API |
| `SettingsTest` | Accent/scheme/threshold sanitization, label fallbacks |
| `UninstallTest` | Opt-in gating and the settings/uninstall option inventory |
| `AccountRenderingTest` | My Account markup, escaping, ownership, search visibility |
| `ClassicCheckoutTest` | Validation, default preservation, checkout address capture |
| `BlocksTest` | Block registration, Store API schema, guest data isolation |
| `CompatibilityTest` | Runtime versions and release-header consistency |

## Notes on two deliberate choices

`UnwanTestCase::tear_down()` clears options by the `unwan_` prefix rather than
from a fixed list, so a newly added setting cannot leak between tests.

`UninstallTest` includes `uninstall.php` once per process while the opt-in
option is off, which leaves the file's top-level body inert, and then calls
`unwan_uninstall_current_site()` directly to exercise both the gated and
opted-in paths.
