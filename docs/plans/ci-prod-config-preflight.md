# CI-префлайт прод-конфига (ловить when@prod-ошибки до деплоя)

**Статус:** план готов, ВНЕДРЕНИЕ ОТЛОЖЕНО — после бэклога апгрейда (webmozart/assert 2, phpspreadsheet 3→5,
web-push 10→11, Rector-baseline). Автор-контекст: инцидент прод-деплоя 2026-09-24 (см. ниже).

## Проблема (инцидент, который это предотвращает)

Апгрейд Symfony 8 влит и зелёный по `./run check`, но прод-деплой упал: init-контейнер `manager_php-cli`
(`doctrine:migrations:migrate`) не стартовал с
`Unrecognized options "auto_generate_proxy_classes, proxy_dir" under "doctrine.orm"` — doctrine-bundle 3 убрал
эти опции, а они лежали в **`when@prod`**-блоке `config/packages/doctrine.yaml`.

**Корень слепой зоны:** `./run check` гоняет `APP_ENV=test`, а `when@prod` / `config/packages/prod/*` в test НЕ
грузятся. Значит любой прод-специфичный конфиг (или сервис-wiring, ломающийся только в prod) проходит гейт и
взрывается уже на проде — когда старый контейнер снят, а новый не встал. Тот же класс уже ловили руками:
`framework.yaml` (errors.php), `server_version` — все прод-невидимы в test.

## Решение

Добавить в CI шаг, который **компилирует контейнер в `APP_ENV=prod`** на свежесобранном коде — до деплоя.
Ошибка парсинга/wiring прод-конфига валит CI, деплой не стартует, прод не трогается.

Команда (проверено локально — ловит ровно ту doctrine-ошибку):
```
docker compose -f docker-compose.test.yml run --rm -e APP_ENV=prod -e APP_DEBUG=0 \
  test_php-cli bin/console lint:container --env=prod
```
- `lint:container` компилирует контейнер (парсинг всего конфига, включая `when@prod`) И проверяет типы аргументов
  сервисов → ловит и «Unrecognized options», и wiring-несовместимости (типа `RateLimiterFactory`→Interface), если
  бы они были прод-онли. Шире, чем `cache:clear`.
- Не требует живой БД/redis (компиляция контейнера, без коннекта). Нужны лишь РЕЗОЛВИМЫЕ env-переменные.

## Файлы

- **Изменить:** `.github/workflows/tests.yml` — в job `Static` (там уже собирается `test_php-cli` + composer install)
  добавить шаг ПОСЛЕ composer install:
  ```yaml
        - name: Prod-config preflight (compile container as prod)
          run: >
            docker compose -f docker-compose.test.yml run --rm
            -e APP_ENV=prod -e APP_DEBUG=0
            test_php-cli bin/console lint:container --env=prod --no-debug
  ```
  Если `lint:container --env=prod` потребует APP_SECRET/иные env — прокинуть dummy через `-e` (значения только для
  резолва, компиляция их не валидирует). Проверить локально, какие именно нужны.
- **Проверить:** `.github/workflows/deploy.yml` — деплой должен зависеть от зелёного CI (`needs:`/`workflow_run`),
  чтобы префлайт реально блокировал выкат. Если связи нет — добавить.

## Верификация (ключевой шаг — доказать, что ловит)

1. Внедрить шаг.
2. **Временно** откатить фикс: вернуть `auto_generate_proxy_classes`/`proxy_dir` в `when@prod` doctrine.yaml.
3. Прогнать шаг локально:
   `docker compose -f docker-compose.test.yml run --rm -e APP_ENV=prod test_php-cli bin/console lint:container --env=prod`
   — должен УПАСТЬ с той же «Unrecognized options».
4. Вернуть фикс → шаг зелёный.
5. Убедиться, что обычный `./run check` (env=test) шаг не сломан.

## Заметки на будущее (не в этом плане)

- №2 из обсуждения (deploy-скрипт: init/валидация в throwaway ДО пересоздания серва) и №3 (healthcheck на php-fpm +
  `up --wait`) — отдельная задача про fail-safe сам деплой (старый жив при падении нового). Docker-native авто-откат
  требует Swarm (`rollback_config`); на plain compose — паттерн префлайт+свап. См. отдельный план при желании.
- Миграции для zero-downtime — expand-contract (обратно-совместимые), отдельная дисциплина.
