# План: Inquiries — экстрактор параметров (порт + NetAngels)

Деплой 1 из серии «подбор по письму/на сайте». Соседи: [2-маппер](inquiries-2-mapper-filter.md), [3-движок+хранение](inquiries-3-engine-and-storage.md), [4-веб](inquiries-4-web.md), позже [5-без модели](inquiries-5-templates-and-cache.md), [6-почта](inquiries-6-mail.md). Общий замысел и контракт извлечения — в [podbor-po-pismu.md](podbor-po-pismu.md) и [[project_podbor_po_pismu_extraction]].

## Идея

Новый bounded context `Inquiries`. Этот деплой даёт **извлечение параметров подбора из текста** как порт с провайдер-независимым контрактом и одну реализацию — NetAngels (LLM). Говорит на языке задачи (текст → параметры в терминах проекта), не на языке модели.

Важно: порт сразу проектируется как **точка подмены стратегии** — завтра перед LLM встанут кэш/шаблоны ([план 5](inquiries-5-templates-and-cache.md)) без изменения потребителей. Поэтому вход/выход порта не знают про «модель».

## Решения

- **Порт** `App\Inquiries\Application\Port\InquiryParametersExtractor::extract(InquiryText): ExtractionResult`. Вход — готовый текст (склейку тело+вложения делает движок, план 3). Выход — извлечённые параметры + сырой ответ + метаданные вызова.
- **Реализация** `NetAngelsExtractor` (Infrastructure/Api/NetAngels): ходит через **наш аудируемый HTTP-клиент** (`AuditedHttpClientFactory::wrap` на scoped-клиенте `netangels`, см. [shared-http-client-audit](shared-http-client-audit.md)); `response_format: json_object`, `temperature: 0`, `/no_think`; модель из env (`qwen3.8-flash`).
- **Сборщик промпта** — отдельный класс `SystemPromptBuilder` (знание домена, НЕ провайдера); читает версионируемый системный промпт (файл `podbor-po-pismu.system-prompt.txt` → переносим в `Inquiries/Infrastructure/Resources/prompt/`), версия — в имени/константе. Адаптер получает сборщик через конструктор.
- **Парсер ответа** `ExtractionResponseParser` — отдельный класс (одна схема для всех провайдеров): сырой JSON → `ExtractedParameters` (VO в терминах проекта: substrates/environment/substances/specifiedCoatings/search/thermal*/standard/category/durability/application*). Значения вне enum → null, отброшенное фиксируется в отчёте.
- **FakeExtractor** — для тестов хендлеров без сети (вернёт заданный `ExtractionResult`).
- Провайдер-независимость: смена на Yandex/Ollama = новый адаптер + строка в `services.yaml`, порт и всё выше не трогаются.

## Контракт (DTO)

- `InquiryText` — склеенный текст (+ возможная пометка, какие части из тела/вложений, для метаданных).
- `ExtractedParameters` — поля JSON как VO (enum'ы проекта или null/[]), включая `specifiedCoatings` (имена заданных заказчиком покрытий) и `substances` (имена, резолв в id — в [плане 2](inquiries-2-mapper-filter.md)).
- `ExtractionMetadata` — модель, версия промпта, токены вход/выход, время, стратегия (`llm:netangels`).
- `ExtractionResult { ExtractedParameters, rawJson: string, ExtractionMetadata, droppedValues: list<string> }`.

## Файлы

- `Inquiries/Application/Port/InquiryParametersExtractor.php`
- `Inquiries/Application/DTO/{InquiryText, ExtractedParameters, ExtractionMetadata, ExtractionResult}.php`
- `Inquiries/Infrastructure/Api/NetAngels/{NetAngelsExtractor, SystemPromptBuilder, ExtractionResponseParser}.php`
- `Inquiries/Infrastructure/Resources/prompt/system-prompt.v1.txt` (перенос из docs/plans)
- `Inquiries/Infrastructure/Api/FakeExtractor.php` (или в tests/Support)
- `config/packages/http_client.yaml` — scoped-клиент `netangels` (base_uri/заголовки/timeout, см. podbor); `config/services.yaml` — обёртка через `AuditedHttpClientFactory::wrap`, биндинг модели из env.
- `.env` — `NETANGELS_BASE_URL`, `NETANGELS_API_KEY`, `NETANGELS_MODEL`.

## Тесты

- Unit `ExtractionResponseParser` — JSON → `ExtractedParameters`; значение вне enum → null + в `droppedValues`; `substances`/`specifiedCoatings` как есть.
- Functional `NetAngelsExtractor` на `MockHttpClient` — проверяем форму запроса (model/response_format/messages) и разбор канонного ответа; **без реальной сети**.
- (ручная сверка на реальных письмах — через стенд, не в CI.)

## Развилки

- Где лежит промпт и как версионируется (файл с версией в имени vs таблица). Предлагаю файл в Resources, версия в имени.
- Поддержка `response_format: json_schema` у NetAngels (жёстче json_object) — проверить; если есть, парсер валидирует только значения.
- Хранить ли `droppedValues` в результате (для показа «что отбросили») — да, пригодится в UI плана 4.
