# File Storage — Деплой 2: перенос Certificates на единый `FileStorage`

Часть серии «Единое хранилище файлов». Backbone (Деплой 1) **влит в main** (PR #73). Зонт-дизайн:
`docs/plans/file-storage-shared-design.md`. Соседний план: `file-storage-1-backbone.md`.

## 1. Цель

Перевести контекст Certificates с локального `DocumentFileStorage` на общий `Shared\Domain\File\FileStorage`.
Снять хардкод PDF-хранилища, ввести `CertificatePurpose`, перенести существующие сканы в единый стор,
удалить legacy-класс и его отдельный Flysystem-адаптер.

## 2. Развилка (снята пользователем)

Существующие сканы лежат в **отдельном** адаптере `document_scans` (`var/uploads/certificates/document_scans/<uuid>.pdf`),
единый стор — в `file_storage` (`var/uploads/file_storage/`). Решение: **вариант A — переложить файлы** в единый
стор по layout `certificates/scan/{docId}/{uuid}.pdf`, заполнить реестр, `Document.file` перевести с ключа на uuid,
адаптер `document_scans` ретайрить. (Вариант B «filesystem по purpose» отклонён.)

## 3. Модель до/после

- **До:** `Document.file` (string, nullable) = сырой ключ `<uuid>.pdf`; физика в адаптере `document_scans`;
  хендлеры и `DownloadAction` зовут `DocumentFileStorage::{store,delete,readStream}`.
- **После:** `Document.file` = **uuid `StoredFile`** (та же string-колонка, миграция схемы не нужна); физика в
  `file_storage` через `FileStorage`; `CertificatePurpose::Scan` несёт префикс/лимиты (20 МБ, `application/pdf`).
- Twig трогать не нужно: использует только `doc.hasFile` (bool) и download-роут по `doc.id` — сырой `file`
  нигде не читается. `DocumentDTOTransformer` (`$dto->file = getFile()`, `hasFile = null !== getFile()`) остаётся —
  семантика `file` меняется на uuid, `hasFile` не ломается.

## 4. Порядок релиза (важно)

Деплой кода и миграция данных — один релиз, строго по порядку:
1. Выкатить код (хендлеры/DownloadAction читают через `FileStorage` по uuid).
2. **Сразу** прогнать `app:certificates:migrate-scans-to-file-storage` (идемпотентно, с `--dry-run` для проверки).
Между шагами 1 и 2 скачивание существующих документов недоступно (их `Document.file` ещё старый ключ →
`FileStorage::get` вернёт null → 404). Certificates админский, окно узкое; зафиксировано осознанно.
Ретайр адаптера `document_scans` — только после подтверждения, что прод мигрирован (шаг 6).

## 5. Задачи

### Task 1 — `CertificatePurpose`
- Create: `app/src/Certificates/Domain/File/CertificatePurpose.php` — `enum ... implements FilePurpose`,
  `case Scan='scan'`; `storagePrefix()='certificates/scan'`; `key()='certificate.scan'`;
  `constraints()=new FileConstraints(20*1024*1024, ['application/pdf'])`.
- Test: `tests/Unit/Certificates/Domain/File/CertificatePurposeTest.php` — prefix/key/constraints.

### Task 2 — Ревайр хендлеров и DownloadAction на `FileStorage`
- `CreateDocumentCommandHandler`: `DocumentFileStorage` → `FileStorage`;
  `$document->setFile($this->storage->store(CertificatePurpose::Scan, (string) $document->getId(), $command->file)->id())`.
  (Store после доменной валидации — как сейчас.)
- `UpdateDocumentCommandHandler`: `store(...)->id()` в `setFile`; старый uuid сносить `FileStorage::remove($old)`.
- `DeleteDocumentCommandHandler`: `FileStorage::remove($file)` вместо `delete`.
- `DownloadAction`: `FileStorage::readStream($document->file)`. **Реализация (отклонение, одобрено при
  ревью):** Content-Type оставлен захардкоженным `application/pdf`, имя — из `document.title` (как было).
  `CertificatePurpose` допускает только PDF, а `originalName` мигрированных строк = имя документа, не
  клиентский файл — брать его в имя скачивания хуже. Диспозиция/ASCII-фолбэк сохранены.
- Обновить функциональные тесты `DocumentUseCasesTest`, `DocumentControllerTest`: инъекция `FileStorage`
  вместо `DocumentFileStorage`; проверки store→uuid→readStream по свежесозданным документам (без миграции).
- services.yaml: снять биндинг `DocumentFileStorage` (после удаления в Task 3).

### Task 3 — Удаление legacy
- Delete: `app/src/Certificates/Infrastructure/Storage/DocumentFileStorage.php` (грепнуть — вызовов не осталось).
- services.yaml: убрать блок биндинга `DocumentFileStorage` ($filesystem document_scans).
- services.yaml: убрать `public: true` у алиаса `FileStorage` — теперь есть реальный DI-потребитель
  (Certificates-хендлеры), контейнер сервис удержит; оставить чистый алиас.

### Task 4 — Миграционная команда `app:certificates:migrate-scans-to-file-storage`
- Create: `app/src/Certificates/Infrastructure/Console/MigrateScansToFileStorageCommand.php`.
- Инъекции: оба `FilesystemOperator` (`document_scans` для чтения, `file_storage` для записи),
  `StoredFileRepositoryInterface`, `DocumentRepositoryInterface`, `EntityManagerInterface`.
- Логика на каждый Document с `file` вида `<uuid>.pdf` (старый ключ):
  - Идемпотентность: если `file` не матчит `*.pdf` (уже uuid) ИЛИ реестр уже содержит строку id=uuid → skip.
  - Прочитать байты из `document_scans` по старому ключу; `$uuid = basename(file,'.pdf')`.
  - `$stored = StoredFile::stored($uuid, CertificatePurpose::Scan, (string)$docId, name, 'application/pdf', 'pdf', strlen(bytes), now)`.
  - Записать байты в `file_storage` по `$stored->storageKey()` (skip если уже есть).
  - `repository->add($stored)`; `document->setFile($uuid)`; сохранить документ.
  - Флаги: `--dry-run` (только отчёт), `--delete-old` (снести из document_scans после успешной записи, по умолчанию нет).
  - Отчёт: сколько мигрировано/пропущено/ошибок.
- Test: `tests/Functional/Certificates/.../MigrateScansToFileStorageCommandTest.php` — создать документ со
  старым ключом (записать байты в document_scans напрямую + setFile('<uuid>.pdf')), прогнать команду,
  проверить: строка в реестре, байты в file_storage по новому ключу, `Document.file` == uuid, повторный
  прогон — no-op (идемпотентность).

### Task 5 — Ретайр адаптера document_scans (ПОСЛЕ прод-миграции, отдельным шагом)
- Убрать адаптер/filesystem `document_scans` из `oneup_flysystem.yaml`, параметр `document_scans_upload_dir`
  из `services.yaml`. **Не делать в этом деплое, если прод ещё не мигрирован** — вынести в отдельный
  cleanup-коммит/релиз или в конец, только после подтверждения. Помечено как follow-up.

## 6. Тесты и гейты

Unit: `CertificatePurpose`. Functional: обновлённые Document use-cases/controller + миграционная команда
(в контейнере, изолированный стек `filestorage_wt`). Финал — `./run check` (style/phpstan/unit/functional).

## 7. Риски

- Окно недоступности скачивания между деплоем кода и миграцией (см. §4) — узкое, админский контекст.
- Разные Flysystem-корни — миграция читает из одного адаптера, пишет в другой; команда идемпотентна и
  с `--dry-run`; `--delete-old` по умолчанию выключен (сначала убеждаемся, что скопировалось).
- Не забыть: `Document.file` семантически меняется (ключ→uuid) — все читатели (`DownloadAction`,
  `DTOTransformer`) переведены; Twig не затронут.

## 8. Статус

Черновик, развилка снята (вариант A). Ветка `feat/file-storage-certificates` от main (backbone влит).
Git ведёт разработчик ([[feedback_no_commits]]); коммиты по задачам — по явной команде.
