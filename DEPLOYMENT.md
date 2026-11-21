# Deployment

Production-инстанс приложения работает на single-VPS через docker-compose, с двухуровневым Caddy:
host Caddy (apt, держит TLS + 80/443) → project Caddy (Docker, HTTP-only) → php-fpm.

Переход файлового хранилища состоит из двух обязательных релизов: `docs/plans/file-storage-production-permissions-1.md`, затем `docs/plans/file-storage-production-permissions-2.md`. Второй релиз откажется менять владельцев без маркера готовности `app/var/.file-storage-acl-ready-v1` от первого.

## Поток деплоя

1. Локально: `git tag v0.X.Y && git push origin v0.X.Y`.
2. GitHub Actions (`.github/workflows/deploy.yml`):
   - запускает тесты и собирает три образа `php-fpm`, `php-cli`, `supervisor` → `ghcr.io/vanzhin/coating-monolith-<service>:<tag>`;
   - синхронизирует серверный checkout с тегом и генерирует `.env` из GitHub Secrets/Variables;
   - собирает frontend и загружает новые образы, пока старые контейнеры обслуживают трафик;
   - однократно мигрирует права общего хранилища: backup → временные ACL для UID `33`, `82`, `10001` → владелец `10001:10001`;
   - запускает init: миграции БД, JWT и cache warmup от пользователя `app`;
   - переключает контейнеры через `up -d --wait`, перезапускает Caddy и проверяет файловые операции от `app` в PHP-FPM и Supervisor;
   - при повторных деплоях пропускает рекурсивную миграцию прав по маркеру `app/var/.file-storage-permissions-v1`.

После успешного workflow приложение доступно на `https://1helper.ru`.

## Что должно быть настроено заранее (one-time)

См. `docs/superpowers/plans/2026-06-25-deploy-server.md`, Task 6 — там пошагово описан provisioning VPS:
- deploy-юзер с SSH-ключом, заблокированный root по SSH;
- Docker Engine + compose plugin из apt-репо Docker;
- host Caddy (apt) + `/etc/caddy/Caddyfile`;
- UFW (22/80/443);
- директория `/var/www/sites/1helper/`, склонированный репо, заполненный `.env`.

## GitHub Secrets / Variables

### Secrets
- `SSH_HOST` — IP нового VPS.
- `SSH_USER` — `deploy`.
- `SSH_KEY` — приватный SSH-ключ deploy-юзера.
- `APP_SECRET` — Symfony app secret.
- `DB_PASSWORD`, `DB_HOST`, `DB_PORT` (последние два используются Symfony внутри контейнера).
- `JWT_PASSPHRASE` — для JWT-бандла.
- `LOG_TELEGRAM_BOT_KEY` — если используется Telegram-логгер.

### Variables
- `DOMAIN` — `1helper.ru`.
- `DB_USER`, `DB_NAME`, `DB_EXTERNAL_PORT` — параметры Postgres.
- `REDIS_HOST` — `manager_redis`.
- `APP_NAME`, `DEFAULT_FROM_ADDR`, `DEFAULT_FROM_NAME`, `MAILER_DSN`.
- `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY` — публичные ключи JWT.
- `LOG_TELEGRAM_CHANNEL`.

`HOST_NGINX`, `NETWORK`, любые `ELASTIC_*` — больше не нужны, можно удалить.

## Мониторинг и оперативка

### Проверка статуса
```bash
cd /var/www/sites/1helper
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f --tail=100
```

### Логи отдельных сервисов
```bash
docker compose -f docker-compose.prod.yml logs caddy --tail=200
docker compose -f docker-compose.prod.yml logs manager_php-fpm --tail=200
docker compose -f docker-compose.prod.yml logs manager_db --tail=200
```

### Host Caddy
```bash
systemctl status caddy
journalctl -u caddy -n 100 -f
```

### Подключение к БД
```bash
docker compose -f docker-compose.prod.yml exec manager_db psql -U $DB_USER -d $DB_NAME
```

### Ручной migrate / cache:clear
```bash
docker compose -f docker-compose.prod.yml --profile init run --rm --user 10001:10001 manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.prod.yml --profile init run --rm --user 10001:10001 manager_php-cli php bin/console cache:clear --env=prod
```

## Откат

Если миграция прав или init завершились ошибкой до `up`, старые контейнеры продолжают работать через legacy ACL; обратный `chown` не нужен.

Если второй релиз уже переключил контейнеры, откатываться нужно на полный тег первого релиза. Checkout обязателен: Supervisor монтирует `conf.d` с хоста, и старые образы несовместимы с новым `user=app`. Legacy ACL должны быть ещё на месте.

```bash
cd /var/www/sites/1helper
PREVIOUS_TAG=v0.X.Y-first-release
git fetch --all --tags --prune
git reset --hard "$PREVIOUS_TAG"
sed -i "s/^TAG=.*/TAG=${PREVIOUS_TAG}/" .env
export TAG="$PREVIOUS_TAG"
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --remove-orphans --wait
docker compose -f docker-compose.prod.yml restart caddy
printf '%s\n' "$PREVIOUS_TAG" > .last-successful-tag
```

Deploy сохраняет образы текущего и последнего успешного тегов локально; его имя хранится в `.last-successful-tag` и не теряется при неудачном повторном деплое. После отката проверить `supervisorctl -c /etc/supervisor/supervisord.conf status`, логи и файловые операции обоих runtime UID.

### Завершение перехода прав

Резервная копия и исходные ACL находятся в `app/var/.file-storage-permissions-backup-v1`. Мигратор останавливается при обнаружении символьных ссылок; такое дерево разбирается вручную.

Только после закрытия окна отката удалить ACL старых UID:

```bash
cd /var/www/sites/1helper
docker compose -f docker-compose.prod.yml --profile init run --rm \
    --entrypoint bash manager_php-cli /app/bin/file-storage-permissions finalize-legacy-acl
```

После этой команды откат на образы с UID `33`/`82` без повторной root-подготовки ACL запрещён.

## Backup БД (ручной)
```bash
docker compose -f docker-compose.prod.yml exec manager_db pg_dump -U $DB_USER $DB_NAME \
    > backup_$(date +%Y%m%d_%H%M%S).sql
```

> Регулярные автоматические бэкапы — отдельная задача, в этот план не входит.

## Безопасность

- UFW открывает только 22/80/443. Postgres (`127.0.0.1:5432`) и Redis недоступны снаружи.
- TLS-сертификат держит host Caddy в `/var/lib/caddy/.local/share/caddy/`, авто-обновление встроено.
- Root по SSH заблокирован, заходим как `deploy` по ключу.
