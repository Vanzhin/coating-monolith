# Устойчивый деплой: «не смог — старое работает» (migrate-first)

**Проблема.** Сейчас `manager_php-cli` (one-shot: wipe cache + **migrate** + jwt + warmup + chown) связан с `php-fpm`/`supervisor` через `depends_on: service_completed_successfully`. При `docker compose up -d` compose ОСТАНАВЛИВАЕТ старый `php-fpm`, а новый ждёт успешного инита. Инит упал (миграция/что угодно) → новый `php-fpm` не стартовал, старый уже убит → **прод лёг**. Риск-шаги идут ПОСЛЕ того, как тронули живое.

**Решение (Вариант 1, паттерн dev-partner-group «migrate-first»).** Вынести весь риск (инит+миграции) в отдельный гейт ДО свопа. Упал гейт — `up -d` не выполняется, старые контейнеры продолжают обслуживать. После успеха — быстрый своп + healthcheck нового php-fpm.

## Изменения

### 1. `docker-compose.prod.yml`
- `manager_php-cli`: добавить `profiles: ["init"]` — исключить из обычного `up -d` (запускается только явным `--profile init run --rm`).
- `manager_php-fpm` и `manager_supervisor`: УБРАТЬ `depends_on: manager_php-cli: service_completed_successfully` (инит теперь гоняется отдельным шагом ДО `up`). Оставить `depends_on` на `manager_db`/`manager_redis`.
- `manager_php-fpm`: добавить `healthcheck` (TCP-пинг FastCGI-порта 9000) — чтобы `up --wait` дождался реально живого fpm, а не просто «контейнер запущен».

### 2. `.github/workflows/deploy.yml` (job `seamless-deploy`, хвост)
Заменить связку `pull` → `up -d` на:
```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml --profile init pull            # образ init тоже
# ГЕЙТ: инит+миграции отдельным one-shot ДО свопа. Упал → set -euo pipefail рвёт скрипт,
# up -d не доходит, старый php-fpm НЕ тронут → прод жив.
docker compose -f docker-compose.prod.yml --profile init run --rm manager_php-cli
# Своп только после успешного инита; --wait ждёт healthcheck нового php-fpm.
docker compose -f docker-compose.prod.yml up -d --remove-orphans --wait
# Caddy резолвит manager_php-fpm при старте; после пересоздания fpm обновляем DNS.
docker compose -f docker-compose.prod.yml restart caddy
```
`set -euo pipefail` уже стоит в начале SSH-скрипта — любой упавший шаг обрывает деплой.

### 3. Удалить `deploy.sh`
Старый ручной скрипт делает `docker-compose down` затем `up` = гарантированный даунтайм + может увести прод, если кто-то запустит. Реальный деплой — `deploy.yml`. Удаляем, чтобы не было футгана.

## Границы / заметки
- **Backward-compatible миграции обязательны** (expand-contract): между `run --rm migrate` и свопом старый код кратко работает на новой схеме. Проект это уже соблюдает (идемпотентные, аддитивные; строгие NOT NULL — отдельным деплоем после бэкфилла).
- Авто-откат на прошлый тег при неудачном `--wait` — НЕ в этой итерации (усложняет; прошлые образы на диске есть, откат вручную). Можно добавить следующим шагом.
- Стейджинг `git reset --hard`/сборки ассетов (чтобы падение до свопа не задевало живой `public/build`) — вторично, отдельной задачей; главный убийца (связка миграций/свопа) закрывается здесь.
- Проверка: `docker compose -f docker-compose.prod.yml config` (валидность), `--profile` не поднимает init в обычном `up`. Полноценно проверяется только на выкате.
