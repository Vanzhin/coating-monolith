# Deployment

Production-инстанс приложения работает на single-VPS через docker-compose, с двухуровневым Caddy:
host Caddy (apt, держит TLS + 80/443) → project Caddy (Docker, HTTP-only) → php-fpm.

## Поток деплоя

1. Локально: `git tag v0.X.Y && git push origin v0.X.Y`.
2. GitHub Actions (`.github/workflows/deploy.yml`):
   - матрица собирает три образа `php-fpm`, `php-cli`, `supervisor` → `ghcr.io/vanzhin/coating-monolith-<service>:<tag>`;
   - SCP'ит `docker-compose.prod.yml` на сервер в `/var/www/sites/1helper/`;
   - SSH'ом генерит на сервере `.env` из GitHub Secrets/Variables;
   - `docker compose pull && docker compose up -d`;
   - `docker compose exec manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction`;
   - `docker compose exec manager_php-cli php bin/console cache:warmup`;
   - чистит старые образы.

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
docker compose -f docker-compose.prod.yml exec manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.prod.yml exec manager_php-cli php bin/console cache:clear --env=prod
```

## Откат

Реестр образов GHCR хранит все теги. Чтобы откатиться:
```bash
cd /var/www/sites/1helper
export TAG=v0.X.Y-1  # предыдущий рабочий тег
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

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
