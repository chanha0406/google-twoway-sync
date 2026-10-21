#!/bin/sh
# Runs tests/Unit in a throwaway composer container. NC_SRC is a Nextcloud
# server tree (for Sabre); it is mounted read-only.
set -e
cd "$(dirname "$0")/.."
NC_SRC=${NC_SRC:-/ssd1/nextcloud}
docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
	-v "$PWD":/app -v "$NC_SRC":/nc:ro -w /app composer:2 sh -c '
	[ -x vendor-bin/phpunit/vendor/bin/phpunit ] || composer --working-dir=vendor-bin/phpunit install --no-interaction --no-progress -q
	vendor-bin/phpunit/vendor/bin/phpunit -c tests/phpunit.unit.xml "$@"' sh "$@"
