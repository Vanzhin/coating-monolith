# CI: тесты как gate перед build/deploy

## Задача

Сейчас `.github/workflows/deploy.yml` триггерится на `push tags v*` и сразу
идёт `build` → `seamless-deploy`. Тесты в проекте есть
(`app/tests/{Unit,Functional}`, phpstan level 6, php-cs-fixer), но в CI не
прогоняются вообще. Если код красный, деплой всё равно уходит.

Нужно: при попытке деплоя (тег `v*`) сначала прогонять тесты; если красные —
дальше не идти. Дополнительно ловить регрессии до тега: гонять те же тесты
на PR и на push в `main`.

## Решения (утверждены с пользователем)

- **Что гейтим:** Unit + Functional + phpstan + php-cs-fixer (в проекте
  cs-fixer, не phpcs — гоняем через `php-cs-fixer fix --dry-run`).
- **Инфраструктура:** отдельный `docker-compose.test.yml` (в стиле
  dev-partner-group), поднимает postgres+redis+php-cli. Без caddy/fpm/supervisor.
- **Триггеры:** PR + push в `main` + push тегов `v*`.
- **Гейт деплоя:** `build.needs: [test]`, `seamless-deploy.needs: [build]`
  (последнее уже есть).

## План

### Шаг 1. `docker-compose.test.yml`

Новый файл в корне репозитория. Только сервисы для тестов:

- `test_db` — postgres:17-alpine, healthcheck (как в prod compose), в памяти
  (`tmpfs: /var/lib/postgresql/data`) для скорости. Пользователь/пароль/имя
  захардкожены (`app_test`/`app_test`/`app_test`) — секретов не нужно.
- `test_redis` — redis:7.2 (без persistence).
- `test_php-cli` — тот же `docker/php-cli/Dockerfile`, что и в проде.
  `depends_on: [test_db (healthy), test_redis]`. Working dir `/app`,
  `./app:/app:rw`.

Отдельные имена сервисов (`test_*`) чтобы избежать конфликта с dev-compose
если оба поднимутся на одном хосте.

### Шаг 2. `.env.test` доработка

Проверить и при необходимости добавить в `app/.env.test`:

- `DATABASE_URL=postgresql://app_test:app_test@test_db:5432/app_test?serverVersion=17&charset=utf8`
- `REDIS_HOST=test_redis`
- `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=1` (в тестах
  auto_setup=1 — таблица messenger_messages создастся сразу).

Секретов JWT/SMTP/Telegram в тестах быть не должно (Unit не трогают, Functional
которые их зовут — либо мокать, либо skip). Если Functional тесты падают из-за
отсутствующих env — разобраться отдельно перед включением gate.

### Шаг 3. Reusable workflow `.github/workflows/tests.yml`

`on: workflow_call`. Три параллельных job:

1. **static** — `docker compose -f docker-compose.test.yml run --rm test_php-cli`
   гоняет `composer install --no-interaction && vendor/bin/php-cs-fixer fix
   --dry-run --diff && vendor/bin/phpstan analyse -c phpstan.neon
   --memory-limit=1G --no-progress`.
2. **unit** — тот же compose, но `vendor/bin/phpunit tests/Unit
   --colors=always --log-junit tests/_output/junit-unit.xml`.
3. **functional** — поднимает БД (`docker compose -f docker-compose.test.yml
   up -d test_db test_redis`), ждёт healthy, гоняет `bin/console
   doctrine:migrations:migrate --no-interaction --env=test`, затем `phpunit
   tests/Functional --log-junit tests/_output/junit-functional.xml`.

Composer cache — через `actions/cache@v4` по хэшу `composer.lock`.
Артефакты: `tests/_output/junit-*.xml` (`if: always()`).

### Шаг 4. `ci.yml` — новый workflow для PR/main

```yaml
name: CI
on:
  pull_request:
  push:
    branches: [main]
jobs:
  tests:
    uses: ./.github/workflows/tests.yml
```

### Шаг 5. `deploy.yml` — вставить test job

- Добавить job `test: uses: ./.github/workflows/tests.yml` (тот же reusable).
- В `build`: `needs: [test]`.
- `seamless-deploy: needs: [build]` — уже есть.
- Триггер `on: push: tags: v*` не меняем.

Итог: если тесты красные на теге — ни build, ни deploy не запустятся.

## Файлы

- `docker-compose.test.yml` (новый)
- `app/.env.test` (правки)
- `.github/workflows/tests.yml` (новый, reusable)
- `.github/workflows/ci.yml` (новый)
- `.github/workflows/deploy.yml` (правки: добавить job test, `build.needs`)

## Развилки / риски

1. **Functional тесты могут падать локально по не-CI причинам** — перед
   включением gate нужно один раз прогнать их локально и убедиться, что
   зелёные. Если нет — либо чинить, либо временно исключать группу
   `@group deploy-blockers` и гейтить только её.
2. **php-cs-fixer может дать много правок** — если сейчас код формально не
   соответствует .php-cs-fixer.dist.php, `--dry-run` красный. Вариант:
   первый прогон — просто отчёт (не блокирует), после чистки — блокирующий.
3. **phpstan level 6 на всём коде** — если сейчас baseline не сгенерирован,
   могут вылезти сотни ошибок. Проверить `vendor/bin/phpstan analyse`
   локально до включения gate; при необходимости сгенерировать
   `phpstan-baseline.neon`.
4. **Скорость** — весь прогон должен укладываться в разумное время (5-10
   минут), иначе PR-workflow станет мучением. Если Functional долгие —
   параллелить `--groups` или разделить на несколько job.

## Порядок работы

Реализуем ПО ШАГАМ, после каждого показываем результат и ждём подтверждения:

1. `docker-compose.test.yml` + правка `.env.test`. Проверить локально:
   `docker compose -f docker-compose.test.yml run --rm test_php-cli
   vendor/bin/phpunit tests/Unit`.
2. Локально прогнать phpstan и cs-fixer, зафиксировать текущее состояние
   (сколько ошибок, нужен ли baseline).
3. `tests.yml` (reusable).
4. `ci.yml`.
5. Правки `deploy.yml`.
6. Пуш в feature-ветку → PR → убедиться, что CI зелёный. Только после
   этого мерджить.
