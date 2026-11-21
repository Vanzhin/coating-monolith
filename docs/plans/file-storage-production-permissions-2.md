# Права общего файлового хранилища на production — релиз 2: единый runtime UID

Предварительное условие: успешно развёрнут и проверен `docs/plans/file-storage-production-permissions-1.md`. Откат выполняется на него, а не на исходные образы с Flysystem `0700`.

## Цель

Запустить PHP-FPM children и все Supervisor-воркеры под выделенным `app` с UID/GID `10001:10001`, перевести существующие данные на этого владельца и сохранить беспростойный переход и откат через ACL первого релиза.

## Изменения

1. Создать `app` с числовыми UID/GID `10001:10001` во всех трёх образах. Добавить PHP-FPM pool drop-in `user/group = app`; во всех Supervisor programs задать `user=app` и `umask=0002`. Master-процессы могут оставаться root, runtime — нет.
2. В init оставить root только подготовку верхнеуровневых каталогов. Миграции БД, JWT и cache warmup выполнять от `app`; не создавать root-owned cache и не выполнять рекурсивный chown uploads при каждом деплое.
3. До init запустить `app/bin/file-storage-permissions migrate`: проверка маркера готовности первого релиза → идемпотентная подготовка ACL и backup → числовой `chown 10001:10001` только на `var/cache`, `var/log`, `var/uploads`, `config/jwt` → режимы `2770`/`660` для data-каталогов → повторная проверка ACL.
4. До swap проверить create/read/rename/delete от UID `33`, `82`, `10001`. После swap — от `app` в PHP-FPM и Supervisor, healthcheck PHP-FPM и отдельно три долгоживущих Supervisor consumer’а; однократный `php-worker` не должен превращать ожидаемый `EXITED` в ошибку deploy.
5. Сохранить ACL UID `33`/`82` на весь период отката. Откат включает checkout полного тега первого релиза, фиксацию его `TAG` в `.env` и `.last-successful-tag`, чтобы Supervisor получил совместимые `conf.d` и последующие Compose-команды не подняли второй релиз.
6. `finalize-legacy-acl` выполнять только отдельной операцией после закрытия окна отката. После неё откат на UID `33`/`82` без повторной root-подготовки запрещён.

## Затрагиваемые места

- `docker/php-fpm/Dockerfile`, `docker/php-fpm/app.conf`, `docker/php-fpm/entrypoint.sh`.
- `docker/php-cli/Dockerfile`, `docker/supervisor/Dockerfile`, `docker/supervisor/conf.d/*.conf`.
- `app/bin/file-storage-permissions`, `docker-compose.prod.yml`, `.github/workflows/deploy.yml`.
- `DEPLOYMENT.md` — порядок перехода, проверки, откат и финализация ACL.

## Проверки

- Собрать все образы; проверить `id app`, `php-fpm -tt`, фактические UID children и пользователей всех Supervisor programs.
- Проверить Compose и init от `app`, включая повторный запуск и неуспешный init до swap.
- На Linux bind-mount проверить старое дерево, вложенные каталоги, create/read/rename/delete от трёх UID, права новых файлов и повторный деплой по маркеру.
- Проверить откат на релиз 1 с сохранёнными ACL. Отдельно проверить запрет миграции при символьной ссылке.
- Прогнать тесты затронутых контекстов и `app/tests/Functional/Shared/File/`.

## Условия приёмки

- PHP-FPM и Supervisor работают под UID `10001` и успешно управляют старыми и новыми файлами.
- Новые каталоги не зависят от того, какой runtime создал родителя; `777`/`1777` отсутствуют.
- Ошибка init оставляет релиз 1 работающим. Откат после swap возвращает полный релиз 1 и сохраняет доступ к данным.
- Повторный деплой не обходит рекурсивно uploads. Legacy ACL удаляются только после явной эксплуатационной команды.
- После выкладки письмо с допустимым вложением обрабатывается, а при ошибке остаётся непрочитанным и логируется со stack trace.
