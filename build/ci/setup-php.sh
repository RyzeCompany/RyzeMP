#!/bin/bash
# Installs a PocketMine-MP PHP binary and Composer into ./bin and puts them on PATH.
# Replaces pmmp/setup-php-action for GitLab CI. Must be sourced: `source build/ci/setup-php.sh 8.2`
#
# Binaries come from the generic package registry of VanillaPocketMine/PHP-Binaries (package PHP-Binaries, version
# pm<major>-php-<version>-latest). PHP_BINARY_BASE_URL can point at another mirror with the same layout, e.g.
# https://github.com/pmmp/PHP-Binaries/releases/download for pmmp's original releases.

PHP_VERSION="${1:-${PHP_VERSION:-8.2}}"
PM_MAJOR="${PM_VERSION_MAJOR:-5}"
BASE_URL="${PHP_BINARY_BASE_URL:-https://gitlab.com/api/v4/projects/87651115/packages/generic/PHP-Binaries}"
INSTALL_DIR="$(pwd)/bin"
TARBALL="PHP-${PHP_VERSION}-Linux-x86_64-PM${PM_MAJOR}.tar.gz"

if [ ! -x "$INSTALL_DIR/php7/bin/php" ]; then
	echo "Downloading $BASE_URL/pm${PM_MAJOR}-php-${PHP_VERSION}-latest/$TARBALL"
	#the tarball contains bin/php7/...
	curl -fsSL --retry 3 "$BASE_URL/pm${PM_MAJOR}-php-${PHP_VERSION}-latest/$TARBALL" | tar -xz -C "$(dirname "$INSTALL_DIR")" || return 1

	#the binary is built with an absolute extension_dir; point it at the extracted copy
	EXTENSION_DIR="$(find "$INSTALL_DIR/php7/lib/php/extensions" -maxdepth 1 -type d -name '*debug-zts*' 2>/dev/null | head -n 1)"
	PHP_INI="$INSTALL_DIR/php7/bin/php.ini"
	if [ -n "$EXTENSION_DIR" ]; then
		if grep -q '^extension_dir' "$PHP_INI"; then
			sed -i "s|^extension_dir.*$|extension_dir=\"$EXTENSION_DIR\"|" "$PHP_INI"
		else
			printf '\nextension_dir="%s"\n' "$EXTENSION_DIR" >> "$PHP_INI"
		fi
	fi

	curl -fsSL --retry 3 -o "$INSTALL_DIR/php7/bin/composer" https://getcomposer.org/download/latest-2.x/composer.phar || return 1
	chmod +x "$INSTALL_DIR/php7/bin/composer"
fi

export PATH="$INSTALL_DIR/php7/bin:$PATH"
php -v
composer --version
