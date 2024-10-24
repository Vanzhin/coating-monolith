#!/bin/sh
set -e

cd /app
php bin/console --no-interaction lexik:jwt:generate-keypair --skip-if-exists

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
	set -- php-fpm "$@"
fi

exec "$@"