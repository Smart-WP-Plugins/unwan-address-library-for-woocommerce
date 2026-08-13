#!/usr/bin/env bash
#
# Regenerate languages/unwan-for-woocommerce.pot from PHP and block JS sources,
# then merge the new strings into every existing locale .po.
#
# Requires GNU gettext (brew install gettext). Run from the plugin root:
#   npm run i18n:pot
#
# After merging, translate any new/fuzzy entries in the .po files and run
#   npm run i18n:mo
# to recompile the .mo and script-translation .json files.

set -euo pipefail

cd "$( dirname "$0" )/.."

DOMAIN="unwan-for-woocommerce"
POT="languages/${DOMAIN}.pot"
VERSION="$( sed -n "s/^define( 'UNWAN_VERSION', '\(.*\)' );$/\1/p" "${DOMAIN}.php" )"

mkdir -p languages

PHP_LIST="$( mktemp )"
trap 'rm -f "$PHP_LIST" languages/_php.pot languages/_js.pot' EXIT

find . -name '*.php' \
	-not -path './node_modules/*' \
	-not -path './vendor/*' \
	-not -path './wordpress-svn/*' \
	-not -path './build/*' \
	| sort > "$PHP_LIST"

xgettext --language=PHP --from-code=UTF-8 --no-wrap --add-comments=translators: \
	--keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
	--keyword=esc_attr__ --keyword=esc_attr_e \
	--keyword=_x:1,2c --keyword=_ex:1,2c --keyword=esc_html_x:1,2c --keyword=esc_attr_x:1,2c \
	--keyword=_n:1,2 --keyword=_nx:1,2,4c --keyword=_n_noop:1,2 --keyword=_nx_noop:1,2,3c \
	--files-from="$PHP_LIST" -o languages/_php.pot

xgettext --language=JavaScript --from-code=UTF-8 --no-wrap --add-comments=translators: \
	--keyword=__ --keyword=_x:1,2c --keyword=_n:1,2 --keyword=_nx:1,2,4c \
	-o languages/_js.pot src/blocks/editor.js src/blocks/frontend.js

msgcat --no-wrap --use-first languages/_php.pot languages/_js.pot -o "$POT"

# Normalize the stub header xgettext emits.
VERSION="$VERSION" perl -0pi -e '
	my $v = $ENV{VERSION};
	s/\A.*?\nmsgid ""\n/"# Copyright (C) 2026 SmartWP Plugins\n"
		. "# This file is distributed under the GPL-2.0-or-later license.\n"
		. "msgid \"\"\n"/se;
	s/^"Project-Id-Version: .*?\\n"$/"Project-Id-Version: Unwan – Multiple Address Book for WooCommerce $v\\n"/m;
	s{^"Report-Msgid-Bugs-To: .*?\\n"$}
	 {"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/unwan-for-woocommerce/\\n"}m;
' "$POT"

echo "Wrote ${POT} ($( grep -c '^msgid ' "$POT" ) entries)."

shopt -s nullglob
for po in languages/"${DOMAIN}"-*.po; do
	msgmerge --quiet --no-wrap --backup=none --update "$po" "$POT"
	echo "  merged $( basename "$po" )"
done
