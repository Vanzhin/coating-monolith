# Единое хранилище файлов (`Shared/File`) — design-спека

Зонтичный дизайн подсистемы загрузки/хранения файлов. **Черновик спеки** — реализацию НЕ начинать
до апрува и до разбивки на per-deploy impl-планы (§11). Дерево может быть занято другим агентом.

Разбор соседнего проекта `dev-partner-group` (Yii2, самописное хранилище) показал: копировать код
нельзя (другой фреймворк, ActiveRecord, без Flysystem, божественный enum `FileEntityType`), но
**дизайн переносим**. Строим своё на нашем стеке (Flysystem + Doctrine + HttpFoundation), приняв их
проверенную модель. Стартовая позиция у нас лучше: `oneup/flysystem-bundle` уже подключён.

---

## 1. Цель

Единое место в `Shared`, через которое любой контекст грузит, хранит, читает и удаляет файлы:
абстракция хранилища + центральный реестр метаданных + двухфазная загрузка (для мобилы/JS) +
валидация на входе. Первый потребитель для обкатки — **Certificates** (перенос существующего
`DocumentFileStorage`). Дальше подсистему бесплатно используют фото полевых отчётов (field-reports
Д2, [[project_field_reports_feature]]) и управление шаблонами (template-renderer Деплой 2,
[[project_template_renderer_engine]]).

## 2. Scope

**В подсистеме:**
- `Shared/Domain/File/`: порт `FileStorage`, mapped-entity `StoredFile`, VO `FileConstraints`,
  контракт `FilePurpose`.
- `Shared/Infrastructure/File/`: реализация на Flysystem, XML-маппинг + миграция реестра `stored_file`,
  generic upload-endpoint (stage), команда чистки просроченных tmp.
- Перенос Certificates на общий `FileStorage` (первый потребитель) — в Деплое 2.

> **Реализация (Деплой 1).** Отклонения от исходного наброска, зафиксированные при реализации:
> метаданные — **единый mutable Doctrine-entity `StoredFile`** (не «VO + отдельный `StoredFileRecord`»),
> `StorageKeyFactory` не нужен (форма ключа живёт на entity). `StoredFile` **не `final`** — Doctrine
> lazy-ghost прокси (их дёргает аудит-листенер на flush) не проксирует final-классы, как `AuditEntry`.
> `FileResponder` в Деплой 1 не делаем — чтение отдаёт `readStream()`/`toLocalTempFile()`, а HTTP-отдачу
> с авторизацией держит контроллер контекста-потребителя (образец — Certificates `DownloadAction`).

**НЕ в подсистеме (осознанный YAGNI):**
- Presigned/CDN-ссылки на прямую отдачу из S3. Отдача — app-стрим за авторизацией контекста.
  Нативные presigned — потом, аддитивно.
- Кастомные валидаторы соседа (extension/mime/aspect-ratio классы). Берём встроенные Symfony
  `File`/`Image`-констрейнты.
- Антивирус-скан загрузок, дедупликация по хэшу, версионирование файла, публичная галерея «все
  файлы системы». Отмечено как возможное будущее (§9).
- Клиентский drag-drop/прогресс/превью и офлайн-синк — это уже потребитель (field-reports Д2/Д3),
  не backbone (§11, План 3).

## 3. Принятые решения (развилки сняты)

1. **Метаданные — центральный реестр.** Одна таблица `stored_file` на все файлы. Агрегаты
   ссылаются по uuid. `FileStorage` — единственный владелец жизненного цикла (экспайр tmp, чистка
   сирот). Метаданные трактуем как инфра-бухгалтерию, вне агрегата.
2. **`FilePurpose` — контракт (интерфейс), enum в каждом контексте** (Вариант B). Не плоский
   божественный enum в Shared. Shared остаётся агностиком; вокабуляр назначений живёт в контексте
   (см. §5.3). Это расшивает главный узел связанности соседа (`FileEntityType`).
3. **Валидация — встроенные Symfony `File`/`Image`-констрейнты** на входе, не кастомные валидаторы.
   MIME определяем по содержимому (finfo), не по заголовку клиента.
4. **Чтение/отдача — за авторизацией контекста.** Shared даёт `readStream`/`FileResponder`, но «кто
   вправе скачать» решает `*AccessControl` контекста. Генерик-эндпоинт — только аплоад в свою
   tmp-зону (любой залогиненный пишет только к себе).
5. **Хранилище-агностик через Flysystem, S3-ready** (§6). Переезд на внешнее хранилище — смена
   адаптера в конфиге, не переписывание кода.

## 4. Архитектура (слои)

```
Shared/
  Domain/File/
    FileStorage.php                    — порт (интерфейс)
    StoredFile.php                     — mapped-entity реестра (identity + метаданные + форма ключа)
    FileConstraints.php                — VO лимитов (maxBytes, mimeTypes[], maxWidth?, maxHeight?)
    FilePurpose.php                    — контракт назначения (интерфейс)
    StoredFileRepositoryInterface.php  — контракт доступа к реестру
  Infrastructure/
    File/FlysystemFileStorage.php      — реализация порта (Flysystem + реестр)
    Repository/StoredFileRepository.php — Doctrine-реализация реестра
    Database/ORM/File/StoredFile.orm.xml — XML-маппинг
    Database/Migrations/Version*.php   — таблица stored_file
    Controller/File/UploadStagedAction.php — POST /cabinet/file/stage → uuid[]
    Controller/Console/PurgeExpiredFilesCommand.php — чистка просроченных tmp
  Application/File/
    StageFiles/{StageFilesCommand,Handler,Result}.php + StagedFileView.php — слайс генерик-загрузки

{Context}/Domain/File/{Context}Purpose.php  — enum implements FilePurpose (по контексту, Деплой 2+)
```

Каждая единица: `FileStorage` — единственная точка операций над файлами; `StoredFile` — то, что
контекст получает и хранит по uuid; `FilePurpose` — «что это за файл» (путь+лимиты), владеет
контекст; реализация и реестр — инфра, скрыты за портом.

## 5. Контракты домена

### 5.1 Порт `FileStorage`

```php
interface FileStorage
{
    // Прямой путь: файл пришёл в сабмите (админ-формы Certificates/шаблоны).
    public function store(FilePurpose $purpose, string $ownerId, UploadedFile $file): StoredFile;

    // Двухфазный путь (мобила/JS):
    public function stage(string $uploaderId, UploadedFile $file): StoredFile;   // в tmp, с TTL
    public function promote(UuidInterface $uuid, FilePurpose $purpose, string $ownerId): StoredFile;

    // Чтение.
    public function get(UuidInterface $uuid): ?StoredFile;
    /** @return StoredFile[] */
    public function byOwner(FilePurpose $purpose, string $ownerId): array;
    /** @return resource */
    public function readStream(UuidInterface $uuid);
    // Материализует временную локальную копию для либ, требующих путь (PhpWord ImageValue);
    // вызывающий сам удаляет. Работает на любом Flysystem-адаптере.
    public function toLocalTempFile(UuidInterface $uuid): string;

    // Жизненный цикл.
    public function remove(UuidInterface $uuid): void;
    public function removeByOwner(FilePurpose $purpose, string $ownerId): void;
    public function purgeExpired(): int;   // удаляет просроченные tmp, возвращает число
}
```

- **Принимает** `Symfony\Component\HttpFoundation\File\UploadedFile`. Стрим-вход (`storeStream`) —
  точка расширения на будущее (рендер-вывод, импорт из внешнего API), сейчас YAGNI.
- **Возвращает** VO `StoredFile`. Никогда не URL и не агрегат.
- `owner_id` — **строка** (id агрегата контекста; в проекте они Uuid/Ulid, тип разный) → храним
  как строку, чтобы Shared оставался агностиком.

### 5.2 Entity `StoredFile` и VO `FileConstraints`

`StoredFile` — **mutable Doctrine-entity** (не `final`, см. §2): поля `id`, `status`
(`staged`/`stored`), `purpose` (?string), `ownerId` (?string), `uploaderId` (?string),
`originalName`, `mime`, `extension`, `size`, `storageKey`, `createdAt`, `expiresAt` (?).
Статик-фабрики `staged()`/`stored()` строят инстанс и форму ключа; `promote(FilePurpose, ownerId)`
мутирует staged→stored (двигает ключ, сбрасывает TTL, кидает `AppException` при повторном promote).

`FileConstraints` (`final readonly`): `maxBytes`, `mimeTypes: string[]`, `maxWidth?`, `maxHeight?`.
Плоские данные, без Symfony-типов — домен остаётся framework-free. Инфра-край (mapper/форма)
переводит их в Symfony `File`/`Image`-констрейнты.

### 5.3 Контракт `FilePurpose` (Вариант B)

```php
// Shared/Domain/File — знает форму, не список
interface FilePurpose
{
    public function storagePrefix(): string;    // 'certificates/scan'
    public function key(): string;              // кладём в реестр: 'certificate.scan'
    public function constraints(): FileConstraints;
}

// Certificates/Domain/File — вокабуляр контекста живёт в контексте
enum CertificatePurpose: string implements FilePurpose
{
    case Scan = 'scan';
    public function storagePrefix(): string { return 'certificates/'.$this->value; }
    public function key(): string           { return 'certificate.'.$this->value; }
    public function constraints(): FileConstraints {
        return new FileConstraints(maxBytes: 20*1024*1024, mimeTypes: ['application/pdf']);
    }
}
```

Shared не резолвит строку из реестра обратно в enum — ему не нужно: для чтения байтов достаточно
`storage_key`; enum обратно знает уже сам контекст (грузя свой агрегат). Сквозные запросы («все
файлы этого назначения») работают по строковому `purpose`/префиксу.

## 6. Хранилище-агностик (S3-ready) — сторожа

- Всё IO — через `League\Flysystem\FilesystemOperator`. Никаких `fopen`/`file_get_contents`/
  `move_uploaded_file` по вычисленному локальному пути.
- Адаптер и локация — из `oneup_flysystem.yaml` (значения из env). Dev — `local`; prod → S3 =
  поставить `league/flysystem-aws-s3-v3` + сменить адаптер. Кода не трогаем.
- Либам, которым нужен путь (PhpWord `ImageValue`), даём `toLocalTempFile()` — стрим-загрузка во
  временную копию (`sys_get_temp_dir`), вызывающий удаляет. Работает на любом адаптере.
- `mime`/`size` пишем в реестр при загрузке → нет round-trip к удалённому хранилищу на каждое чтение.
- Отдача — app-стрим за авторизацией (одинаково local/S3). Presigned/CDN — потом, аддитивно.
- **Секреты не завязываем на путь** (у соседа HMAC-ключ presigned = путь хранилища). Если presigned
  когда-нибудь появится — ключ из `APP_SECRET`/выделенного env.

Config-набросок `oneup_flysystem.yaml`:
```yaml
oneup_flysystem:
  adapters:
    file_storage_local:
      local: { location: '%file_storage_root%' }   # dev
    # file_storage_s3: { awss3_v3: { client: ..., bucket: '%env(FILE_STORAGE_BUCKET)%' } }  # prod
  filesystems:
    file_storage: { adapter: file_storage_local }
```

## 7. Реестр `stored_file`

| колонка        | тип           | заметка                                            |
|----------------|---------------|----------------------------------------------------|
| id             | uuid, PK      | = uuid файла                                       |
| status         | varchar       | `staged` \| `stored`                               |
| purpose        | varchar, null | `purpose.key()`; null пока staged                  |
| owner_id       | varchar, null | id агрегата; null пока staged                      |
| uploader_id    | varchar, null | кто залил (tmp-зона/права на promote); null у прямого store |
| original_name  | varchar       | имя из клиента (не для пути)                        |
| mime           | varchar       | определён по содержимому (finfo)                   |
| extension      | varchar       |                                                    |
| size           | integer       | INT (наши лимиты — МБ; bigint приезжает строкой и ломает типизированное int-поле) |
| storage_key    | varchar       | ключ во Flysystem                                  |
| created_at     | datetime      |                                                    |
| expires_at     | datetime,null | tmp — задан (TTL), stored — null                   |

- `storage_key` (staged): `tmp/{uploaderId}/{uuid}.{ext}`.
- `storage_key` (stored): `{purpose.storagePrefix()}/{ownerId}/{uuid}.{ext}`.
- `promote` двигает файл во Flysystem (`move(old,new)`), проставляет purpose/owner/status/сбрасывает
  expires_at. Идемпотентность/защита от двойного promote — по `status` (§9).
- Миграция — идемпотентная, `IF NOT EXISTS`, в `Shared/Infrastructure/Database/Migrations/Version*`.

## 8. Потоки

**Прямой (админ-форма, Certificates/шаблоны):** контроллер получает `UploadedFile` из сабмита →
форм-валидация (Symfony-констрейнты из `purpose.constraints()`) → handler зовёт
`store(purpose, ownerId, file)` → `StoredFile` → агрегат хранит `uuid`. Сначала доменная валидация
агрегата, потом `store` — чтобы файл не осиротел (как сейчас в Certificates).

**Двухфазный (мобила/JS, field-reports Д2):**
1. Клиент шлёт файл(ы) на генерик-эндпоинт `POST /file/stage` (любой залогиненный). Сервер применяет
   **широкий guard** (общий лимит размера + белый список mime, без знания финального purpose),
   `stage(uploaderId, file)` → tmp с TTL (напр. 2ч) → отдаёт `uuid`.
2. При сабмите отчёта команда контекста зовёт `promote(uuid, FieldReportPurpose::Photo, reportId)` →
   **повторная валидация против `constraints()` конкретного purpose** → файл переезжает из tmp,
   `uuid` кладётся в агрегат Report.
3. Осиротевшие tmp (залил, не привязал) чистит `PurgeExpiredFilesCommand` по `expires_at`
   (крон/messenger-scheduler).

Мульти-загрузка (несколько фото разом) — эндпоинт принимает массив, возвращает массив uuid.

## 9. Риски и открытые вопросы

- **Миграция данных Certificates.** Существующие сканы уже лежат под `var/uploads/certificates/
  document_scans/{uuid}.pdf`, ключ хранится на агрегате Document, реестра нет. При переносе нужно
  **бэкфилл реестра** (по имеющимся файлам/записям) и решить: сохранить старый layout ключей или
  перенести под новый `{prefix}/{ownerId}/{uuid}`. Рекомендация: бэкфилл + оставить старые ключи
  как есть (storage_key в реестре произвольный, layout — только для новых). Детализируется в Плане 2.
- **Двойной `promote`** одного tmp-uuid (гонки, ретраи синка): защита по `status` — promote только
  из `staged`, иначе no-op/ошибка. Детализируется в Плане 1.
- **Не-атомарность байты↔реестр (известное ограничение).** Запись байт и flush строки — разные
  транзакции. `store`/`stage`: пишем байты → flush; при провале flush есть **компенсация** — удаляем
  осиротевшие байты (`persistOrRollbackBytes`). `promote`: `move` байт → flush; при провале flush
  байты уже на новом ключе, а строка откатится к старому — компенсации нет (реконсиляция вне scope
  backbone). `purgeExpired` подбирает только просроченные staged-строки, но НЕ осиротевшие байты без
  строки. Когда появятся реальные потребители — добавить сверочную чистку хранилища против реестра.
- **Права на `stage`/`promote`:** promote чужого tmp-uuid — запрет (проверка `uploader_id`
  == текущий, либо владение целевым агрегатом через `*AccessControl`).
- **Отдача не должна стать дырой авторизации:** нет центрального «GET файл по uuid». Каждый контекст
  отдаёт через свой контроллер с `*AccessControl` (Certificates DownloadAction — образец).
- Будущее (вне scope): антивирус-скан, дедуп по хэшу, версионирование, presigned/CDN, публичная
  админ-галерея файлов (тогда пригодится tagged-registry `FilePurpose` для человекочитаемых меток).

**Открытия при реализации Деплоя 1 (важно потребителям):**
- **Ответ оборачивается глобальным враппером**: `JsonResponse` уходит клиенту как
  `{"result":"success","status":200,"data":{...},"message":null}` — полезная нагрузка под `data`.
  JS field-reports Д2 читает `response.data.files`.
- **Алиас порта `FileStorage` — `public: true`**: backbone его пока никто не потребляет → контейнер
  инлайнит и выкидывает сервис, тест-контейнер не достаёт. Когда появится потребитель (DI через
  конструктор), `public: true` можно снять.
- **`AppException` из хендлера доходит до контроллера как есть**: `CommandBus` разворачивает
  `HandlerFailedException` (`current($e->getWrappedExceptions())`) → `instanceof AppException` в
  тонком контроллере работает, HTTP-код = код исключения (422).

## 10. Тесты (по правилам проекта)

- **Unit** (зеркалят `src/`): `StoredFile` (staged/stored/promote), `FileConstraints`,
  per-context `*Purpose` enum (`storagePrefix`/`key`/`constraints`).
- **Functional** (реальная БД, контейнер, [[reference_test_run_env]]):
  `FlysystemFileStorage` store/stage/promote/get/byOwner/remove/purgeExpired на local-адаптере.
- **Controller:** `UploadStagedAction` — требует авторизацию, широкий guard режет oversize/bad-mime.
- **Certificates:** существующие тесты зелёные после переноса (регресс-гейт Плана 2).
- Финальный гейт — `./run check` (style/phpstan), не только phpunit ([[feedback_sdd_run_check_gates]]).

## 11. Декомпозиция на деплои (отдельные impl-планы)

Многодеплойная задача → по правилу проекта отдельные самодостаточные планы, не разделы одного файла:

1. **`file-storage-1-backbone.md`** — порт + `StoredFile`/`FileConstraints` + `FilePurpose`-контракт
   + реестр (`stored_file` + миграция) + `FlysystemFileStorage` + генерик `stage`-эндпоинт с
   широким guard + `PurgeExpiredFilesCommand`. **РЕАЛИЗОВАН** (ветка `feat/file-storage-backbone`):
   889 unit + 399 functional зелёные, phpstan/cs-fixer чисты. Расписание чистки — ops (крон), вне кода.
2. **`file-storage-2-certificates.md`** — `CertificatePurpose`, перенос `DocumentFileStorage` →
   `FileStorage`, снятие хардкода PDF, сохранение авторизации `DownloadAction`, **миграция данных**
   (бэкфилл реестра существующих сканов, §9).
3. **`file-storage-3-field-report-photos.md`** — после появления домена `Report` (их Д2). `stage`→
   `promote`, Stimulus drag-drop/прогресс/превью, `FieldReportPurpose::Photo` + constraints,
   офлайн/синк-загрузка (льём при синке). Перекрёстная ссылка на [[project_field_reports_feature]].

## 12. Статус

Апрув дизайна получен (Вариант B, центральный реестр, S3-ready). **Деплой 1 (backbone) РЕАЛИЗОВАН** —
ветка `feat/file-storage-backbone` от `main` (origin/main=6948d78), коммиты по задачам плана
`file-storage-1-backbone.md`. Гейты зелёные: 889 unit, 399 functional (0 fail), phpstan level 6,
cs-fixer. Дальше: Деплой 2 (перенос Certificates + миграция данных) — отдельный план.
