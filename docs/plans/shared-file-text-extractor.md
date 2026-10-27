# План: общий сервис извлечения текста из файла (Shared)

## Идея

Кубик «разборщик из файла»: **принимает файл → отдаёт объект с текстом и статусом**. Чистое механическое извлечение текстового слоя (PDF/DOCX/XLSX), без модели и осмысления. Сканы/картинки (нет текстового слоя) → статус «скан». Нужен подбору-по-письму ([podbor-po-pismu.md](podbor-po-pismu.md)) как шаг перед LLM-экстрактором, но делается **общим на всё приложение** (как HTTP-клиент) — извлечение текста generic, переиспользуемо.

Что НЕ делает: не понимает смысл, не зовёт LLM, не OCR-ит сканы (v2), не склеивает тело письма с вложениями (это логика пайплайна Inquiries — отдельно). Один файл → один объект.

## Решения (согласовано)

- **Размещение — Shared** (расхождение с исходным планом, где разборщик был в Inquiries, — согласовано). Порт + VO в `Shared/Domain/FileText`, реализации в `Shared/Infrastructure/FileText`.
- **Вход — путь к файлу** (`string $path`): `pdftotext` — системный бинарь, читает файл; phpspreadsheet/phpword тоже берут путь. Вложения материализуются из файлового хранилища (Shared/File) во временный путь перед вызовом.
- **Движок PDF — `pdftotext` (poppler) в Docker-образе.** Качество на таблицах опросников проверено (`-layout`), pure-PHP smalot отклонён (слабее). Вызов через **Symfony Process** (даёт таймаут и контроль кода возврата — это нужный ограничитель), без доп-обёртки.
- **XLSX — phpoffice/phpspreadsheet** (уже в проекте), `setReadDataOnly(true)`. **DOCX — phpoffice/phpword** (добавить).
- Диспетчер по mime/расширению выбирает адаптер формата.

## Контракт

```php
// Shared/Domain/FileText
interface FileTextExtractor
{
    public function extract(string $path, ?string $mimeType = null): FileText;
}

enum FileTextStatus { case EXTRACTED; case SCAN; case ERROR; }

final readonly class FileText
{
    public function __construct(
        public string $text,            // извлечённый текст ('' если SCAN/ERROR)
        public FileTextStatus $status,
        public bool $truncated = false, // сработал ли safety-cap длины
        public ?string $error = null,   // причина при ERROR
    ) {}
    public function isExtracted(): bool { return FileTextStatus::EXTRACTED === $this->status; }
}
```

Публичный сервис — один диспетчер, реализующий `FileTextExtractor`; внутри по типу делегирует `PdfTextExtractor` / `DocxTextExtractor` / `XlsxTextExtractor`. Формат не из whitelist / не прочитался → `ERROR`. Текст есть, но пуст/почти пуст → `SCAN`.

## Ограничители (вход недоверенный — письма заказчиков)

Безопасность (обязательно):
- **XXE off** для Office-парсеров (`libxml` без внешних сущностей) — DOCX/XLSX это zip+XML.
- **Только значения, не формулы** в XLSX (`setReadDataOnly(true)`).
- **Пароль-защищённые** → `ERROR`, не падение.
- **Zip-bomb** (распакованный размер DOCX/XLSX) — желательно; в v1 прикрыто лимитом размера входа, полноценный лимит распаковки — вторым заходом.

Ресурсы:
- **Макс. размер входного файла** (`max_file_bytes`) — больше → `ERROR` до траты памяти.
- **Таймаут извлечения** (`extraction_timeout_sec`) — на процесс `pdftotext` (Symfony Process `setTimeout`) и на Office-парсеры.
- **Cap длины выходного текста** (`max_text_chars`) + флаг `truncated` — гигантский ГОСТ не раздувает токены/память.
- **Чистка temp** — `finally` unlink материализованных файлов.

Корректность:
- **Порог «пусто → SCAN»** (`scan_min_chars`) — PDF без текстового слоя даёт пустоту.
- **Валидный UTF-8** на выходе (нормализация/отбраковка битых байт, как в BodyCapper).
- **Whitelist форматов** (PDF/DOCX/XLSX); прочее → `ERROR`.
- **Пустой/битый файл, отсутствует бинарь `pdftotext`** → `ERROR`, грациозно.

## Конфиг (настройки строками, как у HTTP-клиента)

`config/packages/file_text.yaml`:
```yaml
parameters:
    app.file_text.max_file_bytes: 25000000       # 25 МБ
    app.file_text.max_text_chars: 200000         # safety-cap выходного текста
    app.file_text.extraction_timeout_sec: 20     # таймаут процесса извлечения
    app.file_text.scan_min_chars: 16             # ниже → считаем сканом
```
Параметры биндятся в адаптеры через `services.yaml` (`bind`), в коде не зашиты.

## Размещение и файлы

```
Shared/Domain/FileText/
    FileTextExtractor.php     — порт (dispatch-точка)
    FileText.php              — VO результата
    FileTextStatus.php        — enum EXTRACTED|SCAN|ERROR
Shared/Infrastructure/FileText/
    FormatFileTextExtractor.php  — диспетчер по mime/расширению, реализует порт
    PdfTextExtractor.php         — pdftotext через Symfony Process (-layout, timeout)
    DocxTextExtractor.php        — phpoffice/phpword (XXE off)
    XlsxTextExtractor.php        — phpoffice/phpspreadsheet (readDataOnly, XXE off)
    TextNormalizer.php           — UTF-8 + cap + truncated (переиспользует/рядом с BodyCapper)
```
- `config/packages/file_text.yaml` + правки `config/services.yaml` (bind лимитов, тег/регистрация адаптеров в диспетчере).
- Docker: `poppler-utils` в образ php-fpm **и** в тест-образ php-cli (иначе функциональные тесты PDF не пройдут). Найти Dockerfile'ы в `docker/`.
- **Новых composer-зависимостей НЕ нужно**: `phpoffice/phpword` уже установлен (транзитивно), `phpoffice/phpspreadsheet` 3.10 и `symfony/process` v8 — тоже. Единственная внешняя добавка — OS-пакет `poppler-utils`.

## Тесты

Фикстуры — минимальные файлы в `tests/.../fixtures` (коммитим): текстовый PDF, «скан» (image-only PDF или картинка), docx, xlsx, пустой/битый.
- Unit: `FileText`/`TextNormalizer` — cap+флаг `truncated`, валидный UTF-8; диспетчер — выбор адаптера по типу, whitelist → `ERROR`.
- Functional: PDF с текстом → `EXTRACTED` + ожидаемые строки; image-only PDF → `SCAN`; xlsx → `EXTRACTED`; docx → `EXTRACTED`; превышение `max_file_bytes` → `ERROR`; неподдержанный формат → `ERROR`; длинный → `truncated=true`.
- Проверка на реальных файлах из сессии (ОЛ-опросники PDF, Приложение№1.xlsx) — вручную/временным прогоном, т.к. в репо их не кладём.

## Деплой (один)

1. `poppler-utils` в Docker-образы (fpm + cli-тест), пересборка.
2. Код сервиса + конфиг + тесты + `./run check`. Новых composer-пакетов нет (phpword/phpspreadsheet/symfony-process уже в lock).

Потребителей ещё нет (склейка тело+вложения и вызов — в пайплайне Inquiries, отдельно). Кубик самодостаточен и проверяется изолированно.

## Развилки (на потом)

- Полноценный лимит распакованного размера (zip-bomb) — вторым заходом.
- OCR сканов (`SCAN` → vision/tesseract) — v2.
- Кэш извлечённого текста по хешу содержимого (план подбора упоминал) — когда появится повторная обработка одних файлов.
- **Apache Tika** (`vaites/php-apache-tika` v1.4.1 совместим) — один парсер на ВСЕ форматы + OCR сканов, заменил бы 3 адаптера одним. Цена — Tika-сервер (JVM-контейнер), новый сервис в ops. Отклонён для v1 (диспропорционально: нам хватает poppler-бинаря без OCR). Пересмотреть, когда понадобятся OCR сканов / много форматов — тогда Tika вытеснит и per-format адаптеры, и будущий vision-OCR.
