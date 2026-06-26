# Deploy Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Развернуть production-инстанс приложения на новом VPS (Debian 13, 4 vCPU / 4 ГБ / 20 ГБ) по схеме «host Caddy (apt) → project Caddy (Docker) → app stack (Docker)», без Elasticsearch.

**Architecture:** Двухуровневый Caddy: host Caddy на хосте через apt держит публичные 80/443 и Let's Encrypt-серты, маршрутизирует по `Host`-заголовку в `127.0.0.1:8080`. Внутри docker-compose проекта живёт project Caddy (HTTP only), фронтит php-fpm через docker network, отдаёт статику из `./app/public`. Postgres, Redis и supervisor — в Docker, ES-контейнер не поднимается, код Symfony не трогаем.

**Tech Stack:** Debian 13 Trixie, Docker Engine + compose plugin (apt от Docker), Caddy 2 (apt от Debian), `caddy:2-alpine` контейнер, GitHub Actions (`appleboy/ssh-action`, `appleboy/scp-action`), GHCR.

## Global Constraints

- Все правки делаются в текущем git-репозитории `coating-monolith` на ветке от `main`.
- Пользователь самостоятельно делает `git add` и `git commit` — план НЕ запускает git-команды. Каждая Task завершается «Commit checkpoint» с предложенным сообщением.
- На сервере все root-команды выполняются от root (или через `sudo`). Прикладные команды Docker — от deploy-юзера.
- Сообщения в `.env`, конфигах, шаблонах — UTF-8 без BOM.
- AppException / domain rules CLAUDE.md не затрагиваются (инфра-задача).
- Текущий пайплайн CI на тег `v*` сохраняется; редактируется только список build-args/env.
- Все URL пользователя — `https://1helper.ru`.

---

### Task 1: Рефактор `docker-compose.prod.yml`

**Files:**
- Modify: `docker-compose.prod.yml`

**Interfaces:**
- Consumes: env-переменные `${REGISTRY}`, `${IMAGE_NAME}`, `${TAG}`, `${DOMAIN}`, `${DB_USER}`, `${DB_PASSWORD}`, `${DB_NAME}`, `${DB_EXTERNAL_PORT}`, `${NETWORK}` (последний остаётся для исторической совместимости — пусть будет, не обязателен; см. `.env`-heredoc в deploy.yml).
- Produces: новая раскладка сервисов: `manager_php-fpm`, `manager_supervisor`, `manager_php-cli`, `manager_redis`, `manager_db`, `caddy`. Сервис `caddy` биндит `127.0.0.1:8080:80`, использует bind-mount`./infra/Caddyfile:/etc/caddy/Caddyfile:ro` и `./app/public:/app/public:ro`, named volume `caddy_data:/data`.

- [ ] **Step 1: Прочитать текущий файл**

Read `docker-compose.prod.yml`, удостовериться, что текущая раскладка совпадает с тем, что описано в плане (раздел Interfaces выше + наличие сервисов `manager_nginx` и `elasticsearch`).

- [ ] **Step 2: Заменить содержимое файла**

Полностью заменить `docker-compose.prod.yml` на:

```yaml
services:
  manager_php-fpm:
    hostname: ${DOMAIN}
    image: ${REGISTRY}/${IMAGE_NAME}-php-fpm:${TAG:-latest}
    volumes:
      - ./app:/app:rw
    depends_on:
      manager_redis:
        condition: service_started
      manager_db:
        condition: service_healthy
    working_dir: /app

  manager_supervisor:
    hostname: ${DOMAIN}
    working_dir: /app
    image: ${REGISTRY}/${IMAGE_NAME}-supervisor:${TAG:-latest}
    restart: always
    depends_on:
      manager_redis:
        condition: service_started
      manager_db:
        condition: service_healthy
    command: bash -c "/usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf"
    volumes:
      - ./app:/app:rw
      - ./docker/supervisor/conf.d:/etc/supervisor/conf.d
      - ./docker/supervisor/log:/var/log/supervisor

  manager_php-cli:
    hostname: ${DOMAIN}
    working_dir: /app
    image: ${REGISTRY}/${IMAGE_NAME}-php-cli:${TAG:-latest}
    command: bash -c "composer install && php bin/console --no-interaction doctrine:migrations:migrate"
    depends_on:
      manager_redis:
        condition: service_started
      manager_db:
        condition: service_healthy
    volumes:
      - ./app:/app:rw

  caddy:
    image: caddy:2-alpine
    restart: always
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - ./infra/Caddyfile:/etc/caddy/Caddyfile:ro
      - ./app/public:/app/public:ro
      - caddy_data:/data
    depends_on:
      manager_php-fpm:
        condition: service_started

  manager_redis:
    hostname: ${DOMAIN}
    working_dir: /app
    image: redis:7.2.1
    restart: always
    volumes:
      - ./docker/redis/data/:/data:rw

  manager_db:
    image: postgres:17-alpine
    shm_size: 128mb
    restart: always
    healthcheck:
      test: [ "CMD", "pg_isready", "-q", "-d", "${DB_NAME}", "-U", "${DB_USER}" ]
      timeout: 45s
      interval: 10s
      retries: 10
    environment:
      POSTGRES_USER: ${DB_USER}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: ${DB_NAME}
    volumes:
      - ./docker/db/data:/var/lib/postgresql/data
    ports:
      - 127.0.0.1:${DB_EXTERNAL_PORT}:5432
    command: [ "postgres", "-c", "logging_collector=on", "-c", "log_directory=/var/lib/postgresql/data/pg_log", "-c", "log_filename=postgresql.log", "-c", "log_statement=all" ]

volumes:
  caddy_data:
```

Изменения относительно прошлой версии:
- Удалены сервисы `manager_nginx` и `elasticsearch`.
- Добавлен сервис `caddy`.
- `manager_db.ports`: `${DB_EXTERNAL_PORT}:5432` → `127.0.0.1:${DB_EXTERNAL_PORT}:5432` (закрываем порт от внешнего мира).
- Добавлена секция top-level `volumes:` с `caddy_data:`.
- Удалена секция `networks:` (нужна была только под static IP `${HOST_NGINX}` у удалённого nginx; Caddy и остальные находят друг друга по hostname в default docker network).

- [ ] **Step 3: Локальная синтаксическая проверка**

Выполнить (потребуется минимальный набор env vars; если их нет — добавить пустые-заглушки):

```bash
DOMAIN=example.com \
REGISTRY=ghcr.io \
IMAGE_NAME=vanzhin/coating-monolith \
DB_USER=x DB_PASSWORD=x DB_NAME=x DB_EXTERNAL_PORT=5432 \
  docker compose -f docker-compose.prod.yml config -q
```

Expected: команда завершается без вывода (код выхода 0) — YAML валидный, сервисы корректно интерполируются.

- [ ] **Step 4: Commit checkpoint (user)**

User commits modified `docker-compose.prod.yml`. Suggested message:
`refactor(deploy): drop manager_nginx + elasticsearch, add project caddy, tighten db port`

---

### Task 2: Создать `infra/Caddyfile`

**Files:**
- Create: `infra/Caddyfile`

**Interfaces:**
- Consumes: docker-network hostname `manager_php-fpm` (из Task 1).
- Produces: HTTP-only Caddy-конфиг, слушает `:80`, отдаёт статику из `/app/public`, проксирует PHP в `manager_php-fpm:9000`.

- [ ] **Step 1: Создать директорию и файл**

```bash
mkdir -p infra
```

Создать `infra/Caddyfile` со следующим содержимым:

```caddy
:80 {
    root * /app/public
    php_fastcgi manager_php-fpm:9000
    file_server
    encode gzip
}
```

Пояснения:
- `:80` — слушаем контейнерный 80-й порт без TLS. Host Caddy на хосте впереди (Task 6) терминирует HTTPS и проксирует сюда через `127.0.0.1:8080`.
- `root * /app/public` — статика из bind-mount'а `./app/public:/app/public:ro` (см. Task 1).
- `php_fastcgi manager_php-fpm:9000` — Caddy сам разруливает `index.php` и SCRIPT_FILENAME; идёт в FPM-сервис по hostname в docker network.
- `encode gzip` — сжатие включаем на этом уровне, host Caddy просто пропускает (см. спеку «Профит двухуровневой схемы»).

- [ ] **Step 2: Локальная проверка синтаксиса Caddyfile**

```bash
docker run --rm -v "$PWD/infra/Caddyfile:/etc/caddy/Caddyfile:ro" caddy:2-alpine caddy validate --config /etc/caddy/Caddyfile
```

Expected: `Valid configuration` в выводе. Если `php_fastcgi` repsorted с предупреждениями про upstream — нормально, real upstream появится только в compose.

- [ ] **Step 3: Commit checkpoint (user)**

User commits new file `infra/Caddyfile`. Suggested message:
`feat(deploy): add project Caddyfile (http-only, proxy to manager_php-fpm)`

---

### Task 3: Удалить мёртвый код `docker/nginx/`

**Files:**
- Delete: `docker/nginx/` (вся директория)

**Interfaces:** none (после удаления `manager_nginx` из compose в Task 1 эти конфиги больше не используются).

- [ ] **Step 1: Удостовериться, что нет ссылок**

```bash
grep -rn "docker/nginx" . --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=var
```

Expected: единственная оставшаяся ссылка может быть в `DEPLOYMENT.md` — её правим в Task 5. Если есть ссылки в `docker-compose.yaml` (dev-вариант) — Task 3 их НЕ трогает (dev-окружение вне scope этого плана).

- [ ] **Step 2: Удалить директорию**

```bash
rm -rf docker/nginx
```

- [ ] **Step 3: Финальная проверка**

```bash
ls docker/
```

Expected: вывод содержит `db`, `elasticsearch`, `php-cli`, `php-fpm`, `redis`, `supervisor`, но НЕ содержит `nginx`. (Директория `docker/elasticsearch/` остаётся — это конфиг для Dockerfile сборки в dev/будущем; сам контейнер ES в prod не запускаем, но конфиг не мешает.)

- [ ] **Step 4: Commit checkpoint (user)**

User commits deletion of `docker/nginx/`. Suggested message:
`chore(deploy): remove docker/nginx (replaced by project caddy)`

---

### Task 4: Обновить `.github/workflows/deploy.yml`

**Files:**
- Modify: `.github/workflows/deploy.yml`

**Interfaces:**
- Consumes: GitHub Secrets/Variables, перечисленные в текущем workflow (DB_*, JWT_*, MAILER_*, …). Перестаёт потреблять `ELASTIC_*` и `HOST_NGINX`/`NETWORK`.
- Produces: build шаг без ELASTIC build-args; heredoc `.env` без ELASTIC и без HOST_NGINX/NETWORK.

- [ ] **Step 1: Удалить ELASTIC_* из build-args**

В шаге `Build and push Docker image` (это `docker/build-push-action@v5`) удалить из `build-args:` следующие строки:

```
ELASTIC_DSN=${{ vars.ELASTIC_DSN }}
ELASTIC_USERNAME=${{ vars.ELASTIC_USERNAME }}
ELASTIC_PASSWORD=${{ secrets.ELASTIC_PASSWORD }}
ELASTIC_AUTH_ENABLED=${{ vars.ELASTIC_AUTH_ENABLED }}
ELASTIC_CONTAINER_NAME=${{ vars.ELASTIC_CONTAINER_NAME }}
ELASTIC_PORT=${{ vars.ELASTIC_PORT }}
```

- [ ] **Step 2: Удалить ELASTIC_* и HOST_NGINX/NETWORK из heredoc `.env`**

В шаге `Deploy to production` найти heredoc `cat > .env << EOF ... EOF` и удалить из него блок:

```
# Elasticsearch
ELASTIC_DSN=${{ vars.ELASTIC_DSN }}
ELASTIC_USERNAME=${{ vars.ELASTIC_USERNAME }}
ELASTIC_PASSWORD=${{ secrets.ELASTIC_PASSWORD }}
```

Также удалить строки `NETWORK=${{ vars.NETWORK }}` и `HOST_NGINX=${{ vars.HOST_NGINX }}` и `ELASTIC_CONTAINER_NAME=${{ vars.ELASTIC_CONTAINER_NAME }}` из верхнего блока «Docker Compose Variables» — после удаления `manager_nginx`/`elasticsearch` они никем не потребляются.

Должен остаться минимальный набор vars в этом блоке:
```
DOMAIN=${{ vars.DOMAIN }}
DB_EXTERNAL_PORT=${{ vars.DB_EXTERNAL_PORT }}
```

- [ ] **Step 3: Локальная YAML-проверка**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"
```

Expected: команда завершается без ошибок. (Если `python3` нет — visual review без автоматики.)

- [ ] **Step 4: Sanity-grep на остаточные упоминания ES/nginx**

```bash
grep -nE "ELASTIC|HOST_NGINX|manager_nginx|^NETWORK=" .github/workflows/deploy.yml
```

Expected: вывод пустой.

- [ ] **Step 5: Commit checkpoint (user)**

User commits modified `.github/workflows/deploy.yml`. Suggested message:
`ci(deploy): drop ELASTIC/HOST_NGINX/NETWORK from build args and env`

---

### Task 5: Переписать `DEPLOYMENT.md`

**Files:**
- Modify: `DEPLOYMENT.md`

**Interfaces:**
- Consumes: новая раскладка из Task 1–4 + provisioning-шаги из Task 6 + первый деплой из Task 7.
- Produces: актуальная документация по деплою для оператора сервера.

- [ ] **Step 1: Полностью заменить содержимое файла**

Заменить `DEPLOYMENT.md` на:

````markdown
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
export IMAGE_TAG=v0.X.Y-1  # предыдущий рабочий тег
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
````

- [ ] **Step 2: Sanity-чек на отсылки к старому**

```bash
grep -nE "manager_nginx|elasticsearch|certbot|ELASTIC_" DEPLOYMENT.md
```

Expected: пустой вывод.

- [ ] **Step 3: Commit checkpoint (user)**

User commits modified `DEPLOYMENT.md`. Suggested message:
`docs(deploy): rewrite DEPLOYMENT.md for caddy + no elasticsearch`

---

### Task 6: Provisioning VPS (ручной runbook на сервере)

**Files:** no repo changes.

**Interfaces:**
- Consumes: чистый Debian 13 Trixie на новом IP, A-запись `1helper.ru → IP` уже разрешается, root-доступ по SSH (пароль или ключ от провайдера).
- Produces: готовый к деплою VPS с deploy-юзером, Docker, host Caddy с TLS-сертом, UFW, директорией `/var/www/sites/1helper/` и склонированным репо.

Все команды ниже выполняются на сервере, не на dev-машине. Если шаг падает — остановиться и разобраться, не пропускать.

- [ ] **Step 1: Войти как root и обновить пакеты**

```bash
ssh root@<NEW_VPS_IP>
apt update && apt upgrade -y
```

Expected: пакеты обновляются без ошибок.

- [ ] **Step 2: Создать deploy-пользователя с SSH-ключом**

```bash
adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
# Положить публичный SSH-ключ deploy в /home/deploy/.ssh/authorized_keys:
# nano /home/deploy/.ssh/authorized_keys  (или scp ключ с локалки)
chown -R deploy:deploy /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
```

Verify:
```bash
ssh deploy@<NEW_VPS_IP> 'whoami && sudo -n true && echo "sudo: ok"'
```
Expected: `deploy` + `sudo: ok`. Если sudo требует пароль — нужно настроить `NOPASSWD` (необязательно, но удобнее): `echo "deploy ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/deploy && chmod 0440 /etc/sudoers.d/deploy`.

- [ ] **Step 3: Заблокировать root и парольный SSH**

```bash
sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl restart ssh
```

Verify (из новой сессии, чтобы текущий root-вход не оборвался посередине):
```bash
ssh root@<NEW_VPS_IP> 'whoami'
```
Expected: `Permission denied (publickey)` или подобная ошибка. Вход в систему дальше только через `ssh deploy@<NEW_VPS_IP>`.

- [ ] **Step 4: Установить Docker Engine + compose plugin**

Выполнить штатный installer Docker для Debian 13 (документация: https://docs.docker.com/engine/install/debian/). Сокращённый рецепт от deploy@server, с sudo:

```bash
sudo apt install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker deploy
```

Reconnect (`exit` + `ssh deploy@<NEW_VPS_IP>` снова), чтобы группа `docker` подхватилась.

Verify:
```bash
docker version
docker compose version
docker run --rm hello-world
```
Expected: версии не пустые; hello-world запускается без `permission denied`.

- [ ] **Step 5: Установить host Caddy и записать Caddyfile**

```bash
sudo apt install -y caddy
```

Записать `/etc/caddy/Caddyfile`:

```bash
sudo tee /etc/caddy/Caddyfile > /dev/null <<'EOF'
1helper.ru {
    reverse_proxy 127.0.0.1:8080
}
EOF
```

NB: NOT перезагружать Caddy сейчас — порт 80 пока закрыт UFW (Step 6), сертификат не получится.

- [ ] **Step 6: UFW**

```bash
sudo apt install -y ufw
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
sudo ufw status verbose
```

Expected: `Status: active`, видны три allow-правила (22, 80, 443).

- [ ] **Step 7: Reload Caddy и проверить ACME-серт**

```bash
sudo systemctl reload caddy
sleep 5
sudo systemctl status caddy --no-pager
sudo journalctl -u caddy -n 50 --no-pager
```

Expected: status active, в логах есть строка вида `certificate obtained successfully` для `1helper.ru`. Файлы сертов лежат в `/var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/1helper.ru/`.

Если ACME не отработал — проверить, что A-запись `1helper.ru` действительно указывает на новый IP (`dig +short 1helper.ru` с локалки) и что 80/tcp реально пропускает UFW (`sudo iptables -L -n | head`).

- [ ] **Step 8: Создать директорию проекта и клонировать репо**

```bash
sudo mkdir -p /var/www/sites/1helper
sudo chown deploy:deploy /var/www/sites/1helper
cd /var/www/sites/1helper
git clone https://github.com/Vanzhin/coating-monolith.git .
```

Verify:
```bash
ls -la
git log -1 --oneline
```
Expected: репо клонирован, последний коммит совпадает с актуальным main.

- [ ] **Step 9: Создать `.env` для первого ручного запуска**

Скопировать `.env.example` и заполнить production-значения (без `vars/secrets` GitHub Actions, потому что они до первого тега ещё ничего не задеплоят):

```bash
cp .env.example .env
nano .env
```

Минимально заполнить:
- `REGISTRY=ghcr.io`
- `IMAGE_NAME=vanzhin/coating-monolith`
- `IMAGE_TAG=latest` (поменяется первым тегом)
- `DOMAIN=1helper.ru`
- `DB_USER`, `DB_PASSWORD`, `DB_NAME` — придумать пароль, остальное на ваш вкус
- `DB_EXTERNAL_PORT=5432`
- `DB_HOST=manager_db`, `DB_PORT=5432` — для Symfony
- `REDIS_HOST=manager_redis`
- `APP_SECRET=` — `openssl rand -hex 32`
- `APP_NAME=1helper`, `DEFAULT_FROM_ADDR=...`, `DEFAULT_FROM_NAME=...`, `MAILER_DSN=...`
- JWT-ключи: можно временно оставить пустыми, GHA-деплой их перезапишет.

NB: Этот шаг — страховка на случай, если захочется `docker compose up` до первого тега. Реальный prod `.env` будет каждый раз перезаписан heredoc'ом в `.github/workflows/deploy.yml` при пуше тега.

- [ ] **Step 10: Smoke-чек, что HTTPS уже отвечает (даже без приложения)**

С локалки:
```bash
curl -I https://1helper.ru
```

Expected: TLS-handshake проходит (валидный Let's Encrypt cert), и host Caddy отвечает `502 Bad Gateway` или подобный — это нормально, потому что project caddy ещё не поднят. Главное — cert валидный.

Если получается ERROR/SSL handshake failure — вернуться к Step 7.

---

### Task 7: GitHub Secrets/Variables + первый деплой через тег

**Files:** no repo changes.

**Interfaces:**
- Consumes: готовый VPS из Task 6, конфиги из Task 1–5 уже на `main`.
- Produces: рабочий prod-инстанс на `https://1helper.ru`.

- [ ] **Step 1: Прописать SSH-Secrets в GitHub**

В Settings → Secrets and variables → Actions → New repository secret добавить (если ещё не было):
- `SSH_HOST` = IP нового VPS.
- `SSH_USER` = `deploy`.
- `SSH_KEY` = содержимое **приватного** SSH-ключа deploy-юзера (тот, чей `.pub` лежит в `/home/deploy/.ssh/authorized_keys`).

- [ ] **Step 2: Проверить остальные Secrets/Variables**

Свериться со списком в `DEPLOYMENT.md` (раздел GitHub Secrets/Variables) — все упомянутые там переменные должны быть прописаны в GitHub. Особенно: `DB_PASSWORD`, `DB_HOST`, `DB_PORT`, `JWT_PASSPHRASE`, `APP_SECRET`, `MAILER_DSN`, и vars вроде `DOMAIN`, `DB_USER`, `DB_NAME`, `DB_EXTERNAL_PORT`, `REDIS_HOST`.

Старые лишние удалить: `HOST_NGINX`, `NETWORK`, любые `ELASTIC_*`.

- [ ] **Step 3: Запушить тег `v0.1.0`**

С локалки в `coating-monolith`:

```bash
git checkout main
git pull
git tag v0.1.0
git push origin v0.1.0
```

- [ ] **Step 4: Следить за workflow**

В GitHub → Actions открыть запущенный workflow `Build and Deploy`. Дождаться завершения обоих job-ов (`build` матрица + `seamless-deploy`).

Expected: все job зелёные. Если `build` падает — проверять логи сборки (часто — отсутствие vars/secrets в build-args). Если `seamless-deploy` падает на SSH — проверять, что SSH_KEY содержит **именно приватный** ключ, без лишних пробелов.

- [ ] **Step 5: Smoke-чек приложения**

С локалки:
```bash
curl -I https://1helper.ru
```

Expected: `HTTP/2 200` или `HTTP/2 302` (если идёт редирект на логин). TLS валиден.

В браузере открыть `https://1helper.ru` — увидеть страницу логина / стартовую.

На сервере:
```bash
cd /var/www/sites/1helper
docker compose -f docker-compose.prod.yml ps
```

Expected: все шесть сервисов в `Up`, healthcheck `manager_db` — `healthy`.

---

### Task 8: Создать первого админа

**Files:** no repo changes.

**Interfaces:**
- Consumes: рабочий prod из Task 7.
- Produces: возможность войти в админку с заранее известными логин/паролем.

- [ ] **Step 1: Найти доступную console-команду создания юзера**

На сервере:
```bash
cd /var/www/sites/1helper
docker compose -f docker-compose.prod.yml exec manager_php-cli php bin/console list | grep -iE "user|admin"
```

Expected: должна найтись команда вроде `app:user:create-admin`, `user:create`, или подобная. Если ничего не находится — посмотреть `app/src/Users/Infrastructure/Console/` локально в репо.

- [ ] **Step 2: Запустить найденную команду**

Подставить точное имя команды (см. Step 1):

```bash
docker compose -f docker-compose.prod.yml exec manager_php-cli php bin/console <КОМАНДА>
```

Если команда интерактивная — отвечать на запросы (email, пароль). Если не интерактивная — передать аргументы.

Альтернатива (если соответствующей команды нет): создать пользователя через `psql` напрямую — но это запасной вариант, сначала ищем штатный путь.

- [ ] **Step 3: Login-чек**

Открыть в браузере `https://1helper.ru` → войти под только что созданными кредами. Убедиться, что попадаешь в админку.

- [ ] **Step 4: Зафиксировать сиды/фикстуры в задаче (если есть)**

Если в проекте есть `bin/console doctrine:fixtures:load` или собственная команда сидов — выполнить:

```bash
docker compose -f docker-compose.prod.yml exec manager_php-cli php bin/console doctrine:fixtures:load --no-interaction --append
```

(если команды нет — пропустить шаг)

---

## Done

После Task 8: на `https://1helper.ru` отдаётся приложение с валидным TLS-сертом, в админку есть вход. CI на каждый тег `vX.Y.Z` собирает образы и автоматически деплоит на VPS.

Следующие итерации (вне этого плана):
- Backup-стратегия БД.
- Staging-окружение.
- Возврат Elasticsearch как сервиса.
- Мониторинг (Grafana / простой uptime-checker).
