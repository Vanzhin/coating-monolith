# План: Inquiries — движок use-case + агрегат Inquiry (хранение)

Деплой 3. Соседи: [1-экстрактор](inquiries-1-extractor-netangels.md), [2-маппер](inquiries-2-mapper-filter.md), [4-веб](inquiries-4-web.md). Хранение сразу закладывается под будущий **без-модельный** разбор ([план 5](inquiries-5-templates-and-cache.md)).

## Идея

Ядро — одна Application-операция, которую одинаково зовут веб-форма ([план 4](inquiries-4-web.md)) и будущая почта ([план 6](inquiries-6-mail.md)). Оркеструет весь путь и сохраняет агрегат `Inquiry`:

вход (текст и/или файлы) → **разборщик файлов** ([shared-file-text-extractor](shared-file-text-extractor.md)) по каждому вложению → склейка текста → **экстрактор** (порт, план 1) → **маппер** (план 2) → `findByFilter` (Coatings) → `InquiryResult`.

## Решения

- **Движок** `ProcessInquiryHandler` (Command): `ProcessInquiryCommand { ?text, files: list<uploaded> }` → `InquiryResult`. Никаких `if`-ов с бизнес-смыслом — оркестрация.
- **Склейка текста**: тело/текст + текст каждого вложения (разборщик, со статусом EXTRACTED/SCAN/ERROR). Неразобранные — помечаем, но не роняем запрос.
- **Экстрактор — через ПОРТ** (план 1). Сегодня за портом только NetAngels; завтра перед ним кэш/шаблоны (план 5) — движок НЕ меняется. Это и есть точка «без модели».
- **Агрегат `Inquiry`** (Domain) хранит ВСЁ, чтобы (а) разобрать ошибку на любом стыке, (б) питать будущий без-модельный разбор:
  - вход: отправитель (если почта)/текст, вложения (файлы в Shared/File storage; в базе — имя, тип, размер, ссылка, **хеш содержимого**, результат извлечения: текст/скан/ошибка);
  - **сигнатура документа** (для распознавания повторяющейся формы в плане 5);
  - что ушло модели (ровно тот текст) + метаданные (модель, версия промпта, токены, время, **какая стратегия**: llm/cache/template);
  - сырой JSON + `ExtractedParameters` + отчёт маппинга (resolved/unresolved вещества, dropped);
  - параметры фильтра + найденные системы (снимок id в порядке выдачи + количество);
  - статус: получено/разобрано/подобрано/ошибка-на-шаге-N-с-текстом;
  - **правки инженера** (слот) + флаг «одобрено» — питание кэша/шаблонов (план 5).
- Правило домена: «нельзя пометить разобранным без извлечённых параметров» — в агрегате.
- **Хранение вложений** — через Shared/File (`stage → promote`), как в проекте.
- **Доступ** — `InquiryAccessControl` в Application (кто вправе запускать; запуск стоит денег/LLM — см. развилку).

## Файлы

- `Inquiries/Domain/Aggregate/Inquiry/{Inquiry, InquiryStatus, Attachment, AttachmentText, ExtractionRecord, MatchedSystems, DocumentSignature}.php` + `Factory`, `Repository` (интерфейс).
- `Inquiries/Application/UseCase/Command/ProcessInquiry/{ProcessInquiryCommand, ProcessInquiryHandler, InquiryResult}.php`
- `Inquiries/Application/Service/AccessControl/InquiryAccessControl.php`
- `Inquiries/Infrastructure/Repository/InquiryRepository.php` + ORM XML + миграция (таблицы inquiry/attachment, идемпотентно).
- Тесты.

## Тесты

- Functional `ProcessInquiryHandler` с `FakeExtractor` (без сети) + реальным разборщиком + реальным `findByFilter` (БД): текст+файл → `Inquiry` сохранён со всеми слоями (вложения со статусами, JSON, параметры, системы, статус), результат содержит системы.
- Functional: вложение-скан (image-only) → помечено SCAN, запрос не падает, дошёл до модели с текстом тела.
- Unit агрегата: нельзя «разобрано» без параметров; статус-ошибка фиксируется записью.

## Развилки

- **Кто вправе запускать** (стоимость LLM): любой авторизованный кабинета, или ROLE_ADMIN/спец-право? Предлагаю ограничить (запуск тратит деньги) — уточнить.
- Снимок найденных систем: только id+порядок+count (как в podbor-плане), не тексты (меняются со временем).
- Синхронно в запросе или через очередь (LLM 3-4с + ретраи): для веба синхронно ок; для почты — в воркере (план 6). Движок одинаков, различается триггер.
