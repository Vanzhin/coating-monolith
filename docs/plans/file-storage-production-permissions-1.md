# Права общего файлового хранилища на production — релиз 1: совместимость

Связанный план: `docs/plans/file-storage-production-permissions-2.md`.

## Проблема

PHP-FPM работает с UID `33`, Supervisor — с UID `82`, а общий bind-mount `./app/var:/app/var` не даёт им симметричного доступа к вложенным файлам. Вложение письма UID `4` не сохранилось из-за `Permission denied`; письмо осталось непрочитанным для повторной обработки.

Flysystem создаёт private-каталоги с режимом `0700`. Поэтому default ACL сам по себе не решает задачу: при создании каталога его ACL mask может стать `---`, а named ACL для другого UID — неэффективной.

## Цель

Оставить существующие runtime UID, но сделать новые файлы и каталоги совместимыми с ACL для UID `33`, `82` и будущего UID `10001`. Убрать зависимость от `1777`, сохранить доступ старых процессов и подготовить безопасный откат следующего релиза.

## Изменения

1. Для всех local Flysystem adapters задать file modes `0664`/`0660` и directory modes `0770`/`0770`. Это позволяет новым каталогам сохранять ACL mask `rwx`.
2. Добавить `acl` и `su-exec` в PHP-CLI image и `app/bin/file-storage-permissions prepare`: backup, отказ при символьных ссылках, access/default ACL для UID `33`, `82`, `10001`, нормализация режимов `var/cache`, `var/log`, всего `var/uploads` без рекурсивного chmod JWT. После успеха создаётся маркер `var/.file-storage-acl-ready-v1`, без которого второй релиз отказывается менять владельцев.
3. В переходном init этого релиза убрать возврат `1777` на `var/uploads`. После cache warmup повторно применить ACL и проверить mask.
4. До swap выполнить `prepare`; после запуска новых контейнеров повторить `prepare`, чтобы исправить объекты, созданные старыми процессами во время переключения. После swap новые процессы уже используют Flysystem `0770` и не создают новую проблему.
5. Проверить реальные create/read/rename/delete от UID `33` и `82` через `su-exec` в PHP-CLI и через оба runtime-контейнера. Не удалять ACL после завершения релиза.
6. Добавить `exception` в контекст ошибки `InquiryMailboxPoller`; сохранить повтор через непрочитанные письма.

## Затрагиваемые места

- `app/config/packages/oneup_flysystem.yaml`.
- `app/bin/file-storage-permissions`, `docker/php-cli/Dockerfile`.
- Переходные `docker-compose.prod.yml` и `.github/workflows/deploy.yml`: старые runtime UID, `prepare` до init и после swap.
- `app/src/Inquiries/Application/Service/InquiryMailboxPoller.php` и `app/tests/Unit/Inquiries/Application/Service/InquiryMailboxPollerTest.php`.
- `DEPLOYMENT.md` — backup, проверки и запрет удаления legacy ACL.

## Проверки

- Собрать образы, проверить конфигурацию Symfony и права, которые реально создаёт Flysystem.
- На Linux bind-mount создать дерево с разными владельцами, выполнить `prepare`, затем create/read/rename/delete от UID `33`, `82`, `10001`.
- Проверить default ACL и отсутствие неэффективных named ACL на новых каталогах; отдельно проверить JWT без вывода содержимого ключей.
- Проверить повторный `prepare`, неуспешный init и продолжение работы старых контейнеров.
- Прогнать `InquiryMailboxPollerTest` и `app/tests/Functional/Shared/File/`.

## Условия приёмки

- Оба старых runtime UID управляют старыми и новыми файлами без `777`/`1777`.
- Новые каталоги Flysystem имеют режим, совместимый с ACL; UID `10001` также получает доступ для следующего релиза.
- Backup находится в `app/var/.file-storage-permissions-backup-v1`; символьные ссылки останавливают процедуру.
- ACL не удаляются. Переход ко второму релизу разрешён только после успешных проверок на production.
