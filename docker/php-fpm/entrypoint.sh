#!/bin/sh
set -e

# JWT-ключи, миграции, cache:warmup и права на var/cache/var/log настраиваются
# init-контейнером (manager_php-cli в docker-compose.prod.yml). Здесь мы уже в
# состоянии готовности — только exec php-fpm. Никаких команд, пишущих в
# /app/var как root, тут быть НЕ ДОЛЖНО, иначе после дропа в www-data получим
# permission-denied на runtime-записи (monolog dedup, symfony ContainerXxx.php).

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
	set -- php-fpm "$@"
fi

exec "$@"