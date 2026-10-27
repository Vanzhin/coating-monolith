# План: общий HTTP-клиент приложения + аудит исходящих запросов

## Идея

В монолите сейчас **нет** общего HTTP-клиента (ни guzzle, ни symfony/http-client в зависимостях, ни кода, который ходит по HTTP напрямую). Нужен надёжный, настраиваемый, общий на всё приложение способ обращаться к внешним API — и технический **аудит каждого исходящего вызова в БД** (куда били, что отправили, что получили, сколько заняло) для отладки и будущей аналитики.

Первый потребитель — адаптер LLM подбора-по-письму (см. [podbor-po-pismu.md](podbor-po-pismu.md)), но клиент и аудит делаются **общими**, не под конкретную интеграцию.

Что это НЕ делает:
- не содержит доменной логики — чистая инфраструктурная телеметрия, про бизнес ничего не знает;
- не заменяет доменное хранение (например, запись Inquiry «какой текст ушёл модели, сырой JSON, токены» — это отдельно, в адаптере/агрегате контекста, не здесь);
- не тащит ClickHouse сейчас (см. развилку хранилища).

## Решения, принятые при обсуждении

- **Транспорт — `symfony/http-client`, не Guzzle.** Встроенное первым выбором (CLAUDE.md). Scoped-клиенты в `framework.yaml` дают ровно нужную форму: именованный, настроенный клиент на каждую интеграцию (`base_uri`, заголовки, `timeout`, `max_redirects`), автовнедряемый по имени.
- **Надёжность — `retry_failed` (RetryableHttpClient)** из коробки: повтор на 5xx/429/сетевых сбоях. Дефолты: **3 попытки** (трактуем как 3 обращения всего = `max_retries: 2`; если имелось в виду 3 ретрая — поправить на `3`), **таймаут 15 с** на попытку (`timeout: 15`). Задержка/множитель/джиттер — дефолтные Symfony. Своего ретрай-кода не пишем.
- **Аудит — декоратор `HttpClientInterface`**, нулевая доменная логика, общий на всё приложение, в `Shared/Infrastructure/Http/`. Места вызова не меняются — инжектят обычный `HttpClientInterface`.
- **Гранулярность аудита — строка на КАЖДУЮ физическую попытку** (вариант B): проваленная попытка и успешная пишутся отдельными строками, связанными `correlation_id`, с `attempt_number`. Так видна и ошибка, и как она вылечилась ретраем — главное для отладки флапающей сети. Значит аудит стоит ВНУТРИ ретрая.
- **Сток аудита — интерфейс-порт + реализация.** Сейчас Postgres (отдельная append-only таблица, без FK и связей с доменом). ClickHouse — позже, если полезет объём: подмена = один класс + конфиг, декоратор и места вызова не трогаются.
- **Запись аудита — асинхронно через Messenger (`async`, Redis уже есть) и fail-safe.** Декоратор снимает телеметрию и кидает сообщение, хендлер пишет в сток в воркере. Любой сбой аудита — в лог и мимо: реальный вызов никогда не падает и не тормозит из-за аудита.
- **Секреты не храним.** Редакция `Authorization`, api-ключей, секретов в query/заголовках. Тела режем по лимиту (первые N КБ + флаг «обрезано»).

## Архитектура и размещение

Всё в `app/src/Shared/Infrastructure/Http/` (+ `Audit/`). Доменного слоя нет — это чистая инфраструктура.

```
Shared/Infrastructure/Http/
  HttpHeaderRedactor.php        — вырезает Authorization/ключи/секреты из заголовков и query
  BodyCapper.php                — режет тело по лимиту (UTF-8-safe) + флаг обрезки
  CorrelatingHttpClient.php     — внешний декоратор: снимает ЗАПРОС, кладёт в стор по cid, штампует user_data=cid
  AuditedHttpClientFactory.php  — wrap(base, scope): Correlating(Retryable(base, AuditingRetryStrategy))
  Audit/
    HttpAuditRecord.php         — DTO телеметрии (final readonly)
    HttpRequestSnapshot.php     — снимок запроса (метод/host/path/query/заголовки/тело), общий для попыток
    RequestSnapshotStore.php    — in-memory cid → снимок запроса (мост Correlating → Strategy)
    AuditingRetryStrategy.php   — декоратор RetryStrategyInterface: пишет ОТВЕТ каждой попытки, склеивает со снимком запроса
    HttpAuditSink.php           — ИНТЕРФЕЙС-порт: record(HttpAuditRecord): void
    PostgresHttpAuditSink.php   — реализация: DBAL-insert в http_audit_log
    HttpAuditMessage.php        — сообщение Messenger (несёт HttpAuditRecord)
    HttpAuditMessageHandler.php — хендлер: пишет record через HttpAuditSink (в воркере)
```

Механизм per-attempt (уточнён на реализации — исходный «декоратор ответа внутри ретрая» не годится: RetryableHttpClient сам потоково потребляет ответы, обёртка ломает его механику):
- **Запись попытки — через `RetryStrategyInterface`**, не через обёртку ответа. `RetryableHttpClient` зовёт стратегию на каждую завершённую попытку с её контекстом (статус/заголовки/тело/исключение) — это и есть точка per-attempt, не воюющая с внутренностями. `AuditingRetryStrategy` оборачивает `GenericRetryStrategy`: пишет запись, делегирует решение.
- **Запрос** стратегия не видит (контекст — только ответная сторона), поэтому `CorrelatingHttpClient` (внешний) один раз снимает запрос, кладёт в `RequestSnapshotStore` по `correlation_id` и штампует `user_data=cid`. Стратегия читает `getInfo('user_data')`, достаёт снимок, склеивает с ответом попытки.
- **Тело успешных ответов:** стратегия на не-ошибочном пути возвращает `null` на первый вызов — заставляет RetryableHttpClient добуферить тело — и пишет запись на повторном вызове с телом. Иначе тело успеха не снять. Цена: ответы аудируемого клиента буферизуются целиком (не потоково) — для API-вызовов ок, для больших скачиваний клиент не использовать.
- **Композиция:** `CorrelatingHttpClient( RetryableHttpClient( base, AuditingRetryStrategy ) )`. Слои фиксированы, числа — из конфига.
- **Рубильник:** `audit_enabled: false` → фабрика возвращает ретрай без `AuditingRetryStrategy` (без форс-буферизации и записей).
- `user_data` **зарезервирован** под correlation_id у аудируемого клиента (потребителей ещё нет).

Конфиг:
- `config/packages/framework.yaml` — `http_client.scoped_clients.<name>` с `timeout: 15` и `retry_failed: { max_retries: 2 }` (3 попытки всего). (Конкретные scoped-клиенты под интеграции добавляет контекст-владелец; этот план даёт механизм + аудит, без привязки к NetAngels.)
- `config/services.yaml` — обёртка scoped-клиента в `AuditingHttpClient` (через `#[AsDecorator]` или `decorates`), биндинг `HttpAuditSink` → `PostgresHttpAuditSink`.
- `config/packages/messenger.yaml` — маршрут `HttpAuditMessage` → `async`.

## Поток

1. Код инжектит `HttpClientInterface` (scoped-клиент: ретрай снаружи, аудит внутри).
2. RetryableHttpClient делает попытку → зовёт `AuditingHttpClient` → базовый клиент. Декоратор на КАЖДОЙ попытке засекает время и снимает её итог.
3. По завершении каждой попытки декоратор формирует `HttpAuditRecord` (с общим на логический запрос `correlation_id` и своим `attempt_number`): статус/ошибка, заголовки (редактированные), тело (обрезанное), длительность. Кидает `HttpAuditMessage` в `async` (fire-and-forget, в try/catch).
4. При провале RetryableHttpClient повторяет (до 3 попыток) — каждая снова пишется строкой с тем же `correlation_id` и следующим `attempt_number`.
5. `HttpAuditMessageHandler` в воркере пишет record через `HttpAuditSink` → Postgres.
6. Реальный ответ возвращается вызывающему **всегда**, независимо от аудита.

## Поля аудита (таблица `http_audit_log`, чистая телеметрия)

`id`, `correlation_id` (общий у попыток одного логического запроса), `attempt_number`, `created_at`, `client_scope` (имя scoped-клиента), `method`, `host`, `path`, `query` (секреты вырезаны), `request_headers` (jsonb, Authorization редактирован), `request_body` (text, обрезано), `status_code`, `response_headers` (jsonb), `response_body` (text, обрезано), `duration_ms`, `error_class`, `error_message`. Без FK, без связей с доменом — append-only. Индекс по `correlation_id` (группировать попытки) и `created_at` (ретенция/аналитика).

## Надёжность и крайние случаи

- **Fail-safe.** try/catch вокруг снятия телеметрии и dispatch: сбой → лог в канал `http_client`, вызов продолжается. Аудит best-effort.
- **Async.** Запись в БД не в request-пути — через Messenger-воркер (уже крутится).
- **Ленивость ответов.** Symfony-ответы потоковые; чтобы снять тело, декоратор читает контент. Для JSON-API (наш основной кейс) это норм; для стриминговых/больших скачиваний буферизация тела вредна — см. развилку.

## Развилки

- **Снятие тела ответа vs стриминг.** Буферизация тела сломает стриминговых потребителей (SSE, скачивание больших файлов) и раздувает лог. Варианты: снимать тело только для не-стриминговых/по content-type; или делать захват тела настраиваемым per-scope (флаг в конфиге клиента). **Предлагаю:** по умолчанию тело захватываем с жёстким лимитом размера, а для «тяжёлых» scoped-клиентов отключаем флагом. Решить при реализации.
- **Что хранить из тел.** Всегда или только при ошибке/по флагу (объём). Предлагаю: всегда, но с лимитом; флаг «только при ошибке» per-scope на будущее.
- **Редакция тел.** Заголовки/query редактируем точно. Тела могут нести ПДн/секреты (например, запрос к банку). Пока тела не редактируем, только режем по размеру — отметить как риск; точечная редакция тел — отдельная развилка.
- **Размещение интерфейса `HttpAuditSink`.** Формально порт, но доменной семантики ноль — кладём в `Infrastructure/Http/Audit` как инфраструктурный шов (адаптер-граница), а не в Application/Domain. Если ревью решит иначе — перенести, это дешёво.

## Файлы

Код:
- `app/src/Shared/Infrastructure/Http/AuditingHttpClient.php`
- `app/src/Shared/Infrastructure/Http/HttpHeaderRedactor.php`
- `app/src/Shared/Infrastructure/Http/Audit/HttpAuditRecord.php`
- `app/src/Shared/Infrastructure/Http/Audit/HttpAuditSink.php`
- `app/src/Shared/Infrastructure/Http/Audit/PostgresHttpAuditSink.php`
- `app/src/Shared/Infrastructure/Http/Audit/HttpAuditMessage.php`
- `app/src/Shared/Infrastructure/Http/Audit/HttpAuditMessageHandler.php`

Конфиг:
- `app/config/packages/framework.yaml` — секция `http_client` (scoped_clients + retry_failed).
- `app/config/services.yaml` — декоратор + биндинг стока.
- `app/config/packages/messenger.yaml` — маршрут `HttpAuditMessage` → `async`.

Миграция:
- `app/src/Shared/Infrastructure/Database/Migrations/Version*.php` — таблица `http_audit_log` (идемпотентно, `IF NOT EXISTS`).

Зависимость:
- `composer require symfony/http-client` (в контейнере).

## Тесты

- Unit `HttpHeaderRedactor` — `Authorization`/ключи/query-секреты вырезаны, остальное цело.
- Unit `HttpAuditRecord` — обрезка тел по лимиту + флаг «обрезано».
- Функциональный `AuditingHttpClient` на `MockHttpClient` — запись формируется с верными полями; **сбой стока/dispatch НЕ ломает ответ** (fail-safe) — отдельный тест-метод.
- Функциональный ретрай+аудит — провал→успех даёт **две** записи с общим `correlation_id` и `attempt_number` 1 и 2 (вариант B).
- Функциональный `PostgresHttpAuditSink` — реальная вставка строки (реальная БД).
- Smoke конфига — scoped-клиент резолвится, `retry_failed` грузится.

## Этапы / деплой

Один деплой — аддитивная инфра, ничего существующего не ломает (потребителей ещё нет).
1. `composer require symfony/http-client`.
2. Миграция `http_audit_log`.
3. Клиент-конфиг (`framework.yaml`) + декоратор + сток + messenger-маршрут.
4. Тесты + `./run check` (style/phpstan/функциональные).

Первый реальный потребитель (scoped-клиент NetAngels + адаптер) — в плане [podbor-po-pismu.md](podbor-po-pismu.md), отдельно от этого.

## Ops

- Таблица `http_audit_log` растёт — нужна **ретенция**: команда чистки старше N дней (в `./run` или scheduled-таск), либо партиционирование по дате. Заложить сразу, чтобы не раздуло БД.
- Messenger-воркер (`async`, Redis) уже работает — новый хендлер поедет на нём, отдельной инфраструктуры не нужно.
- ClickHouse (будущее): снимет вопрос ретенции TTL-движком и разгрузит Postgres; переключение — новый `HttpAuditSink` + его транспорт, без изменения декоратора и мест вызова.
```
