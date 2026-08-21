#!/usr/bin/env bash
# Install the WordPress core test library and a throwaway test database.
#
# Usage:
#   bash scripts/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-creation]
#
# Example (WordPress 7.1 against a local MySQL):
#   bash scripts/install-wp-tests.sh wordpress_test root '' localhost 7.1
#
# Afterwards, run the suite with:
#   WP_TESTS_DIR=/tmp/wordpress-tests-lib composer run test
#
# Requires: svn, curl, unzip, mysqladmin. The plugin under test is symlinked
# into the throwaway WordPress install alongside WooCommerce, which is expected
# to sit next to this plugin in the same wp-content/plugins directory.

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-creation]"
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"
SKIP_DB_CREATE="${6:-false}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
PLUGIN_SLUG="$( basename "$PLUGIN_DIR" )"
PLUGINS_DIR="$( dirname "$PLUGIN_DIR" )"

if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION="$(
		curl -s https://api.wordpress.org/core/version-check/1.7/ \
			| sed -n 's/.*"current":"\([^"]*\)".*/\1/p' \
			| head -n1
	)"
	echo "Resolved latest WordPress to ${WP_VERSION}"
fi

install_test_suite() {
	echo "Installing the WordPress ${WP_VERSION} test library into ${WP_TESTS_DIR}"
	rm -rf "${WP_TESTS_DIR}"
	mkdir -p "${WP_TESTS_DIR}"

	local svn_tag="tags/${WP_VERSION}"
	if ! svn ls "https://develop.svn.wordpress.org/${svn_tag}/" >/dev/null 2>&1; then
		echo "No develop.svn tag for ${WP_VERSION}; falling back to trunk."
		svn_tag="trunk"
	fi

	svn export -q "https://develop.svn.wordpress.org/${svn_tag}/tests/phpunit/includes" "${WP_TESTS_DIR}/includes"
	svn export -q "https://develop.svn.wordpress.org/${svn_tag}/tests/phpunit/data" "${WP_TESTS_DIR}/data"
}

install_wordpress() {
	echo "Installing WordPress ${WP_VERSION} into ${WP_TESTS_DIR}/src"
	curl -sL -o "${WP_TESTS_DIR}/core.zip" "https://wordpress.org/wordpress-${WP_VERSION}.zip"
	unzip -q "${WP_TESTS_DIR}/core.zip" -d "${WP_TESTS_DIR}"
	mv "${WP_TESTS_DIR}/wordpress" "${WP_TESTS_DIR}/src"
	rm -f "${WP_TESTS_DIR}/core.zip"

	mkdir -p "${WP_TESTS_DIR}/src/wp-content/plugins"
	ln -sfn "${PLUGINS_DIR}/woocommerce" "${WP_TESTS_DIR}/src/wp-content/plugins/woocommerce"
	ln -sfn "${PLUGIN_DIR}" "${WP_TESTS_DIR}/src/wp-content/plugins/${PLUGIN_SLUG}"

	if [ ! -e "${WP_TESTS_DIR}/src/wp-content/plugins/woocommerce/woocommerce.php" ]; then
		echo "WooCommerce was not found at ${PLUGINS_DIR}/woocommerce." >&2
		echo "The test bootstrap loads it from there; install it and re-run." >&2
		exit 1
	fi
}

write_config() {
	cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<PHPEOF
<?php
define( 'ABSPATH', __DIR__ . '/src/' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Unwan Test Suite' );
define( 'WP_PHP_BINARY', 'php' );

define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'WP_DEFAULT_THEME', 'default' );
PHPEOF
}

create_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		return
	fi

	echo "Creating the ${DB_NAME} database"
	mysqladmin create "${DB_NAME}" --user="${DB_USER}" --password="${DB_PASS}" --host="${DB_HOST}" 2>/dev/null \
		|| echo "Database ${DB_NAME} already exists; reusing it."
}

install_test_suite
install_wordpress
write_config
create_db

echo
echo "Done. Run the suite with:"
echo "  WP_TESTS_DIR=${WP_TESTS_DIR} composer run test"
