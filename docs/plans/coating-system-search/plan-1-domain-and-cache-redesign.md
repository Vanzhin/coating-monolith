# Поиск систем покрытий — План 1 (redesign): чистый домен + event-driven кэш

Пересборка архитектуры read-модели для CoatingSystem. Заменяет ранее написанный
`plan-1-projector.md`, который смешивал бизнес-логику агрегата и запись в проекционные таблицы.
Причина переделки — правило проекта «домен vs инфраструктура» (см. CLAUDE.md, критерий):
всё, что относится к самой системе как объекту реального мира — в домене; всё, что нужно только
для быстрого поиска в БД — в инфраструктуре, обновляется событиями, а не полями агрегата.

Второй план (переключение чтения и UI — `plan-2-query-ui.md`) остаётся в силе, только источник
данных для поиска у него теперь — новые кэш-репозитории, а не поля агрегата.

## Общая идея

- **Домен `CoatingSystem`** ничего не знает про сервисы (chainValidator, evaluator), не хранит
  никаких кэш-полей (min/max/compliance/tsvector). У него только собственный состав (substrate,
  слои, теги) плюс runtime-методы, вычисляющие производные величины по своим слоям.
- **Инварианты** (в т.ч. совместимость соседних слоёв) — приватные методы агрегата, вызываемые
  из `postMutate`.
- **Кэш для поиска** живёт в двух отдельных таблицах (`coating_system_search` для 1:1-величин и
  `coating_system_compliance` для 1:N-совпадений). Doctrine ORM их **не мапит** — доступ только
  через thin repository-обёртки.
- **Обновление кэша** — синхронно через domain events (`CoatingSystemMutated`, `CoatingMutated`)
  и subscriber-ы на event.bus. При мутации агрегата `PublishDomainEventsOnFlushListener` собирает
  события и вызывает subscriber в той же транзакции.
- **Runtime UI** (карточка/DTO) — вызывает публичные runtime-методы агрегата и `ComplianceEvaluator`
  напрямую. Кэш не читает — он только для фасетов поиска.

## Секция 1. Домен CoatingSystem

**Удаляется**:
- `App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator`
- `App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidatorInterface`
- Поле `?CoatingSystemChainValidatorInterface $chainValidator = null` в конструкторе `CoatingSystem`.
- Метод `setChainValidator`.
- Поля-кэши `$minBuildingTimeAt20Minutes`, `$maxLayerApplicationMinTemp`, их ORM mapping.
- Приватные методы `recalculateDerivedFields`, `computeMinBuildingTimeAt20Minutes`,
  `computeMaxLayerApplicationMinTemp` — переработать в публичные runtime-методы, см. ниже.

**Остаётся + добавляется**:
- Собственные свойства: `substrate`, `layers`, `tags`, `title`, `description`, `createdAt`, `updatedAt`.
- **Публичные runtime-методы**:
  - `minBuildingTimeAt20Minutes(): ?int` — сумма пересчитанных под фактическую толщину интервалов
    перекрытия по слоям, поверх которых наносится следующий. null, если у любого слоя нет базовой
    точки при 20 °C. Пустая система — null. Один слой — 0.
  - `maxLayerApplicationMinTemp(): ?int` — max мин.температуры нанесения по всем слоям. Пустая
    система — null.
  - `complianceMatches(ComplianceEvaluator $evaluator): ComplianceMatches` — принимает evaluator
    параметром (не хранит), возвращает VO-коллекцию совпадений.
- **Приватный `assertLayersAreChainable(): void`** — проверяет попарно соседние слои через
  `CoatingBase::canBecoveredBy`, кидает `AppException` при несовместимости.
- `postMutate()`: `assertPositionsAreDense()` + `assertLayersAreChainable()` + `raise(new CoatingSystemMutated($this->getId()))` + `touch()`.
- Другие мутирующие сеттеры (`setTitle`, `setDescription`, `setSubstrateAndTreatment`,
  `addTag`/`removeTag`/`replaceTags`) — тоже записывают `CoatingSystemMutated` перед `touch`.

## Секция 2. Domain events + subscribers

**События** в `App\Coatings\Domain\Event\`:

- `CoatingSystemMutated` — payload `string $systemId`. Публикуется агрегатом `CoatingSystem` при
  любой мутации (слои, метаданные, теги).
- `CoatingMutated` — payload `string $coatingId`. Публикуется агрегатом `Coating` в сеттерах,
  влияющих на compliance или FTS-документ систем: `setBase`, `setIsZincRich`, `setApplicationMinTemp`,
  `setDftRange`, `setMinRecoatingInterval`, `setTitle`, `setDescription`.

Публикация — через `Aggregate::raise()` (уже есть в базовом классе), `PublishDomainEventsOnFlushListener` подхватит на
flush → `EventBus` (event.bus, sync).

**Subscribers** в `App\Coatings\Application\Event\`, оба implements `EventHandlerInterface`:

- `RefreshCacheOnCoatingSystemMutatedHandler` — принимает `CoatingSystemMutated`, загружает систему
  по id, вызывает `CoatingSystemSearchCacheRepository::upsert($system)` и
  `CoatingSystemComplianceCacheRepository::rewrite($system, $evaluator)`.
- `RefreshCacheOnCoatingMutatedHandler` — принимает `CoatingMutated`, находит все системы,
  содержащие покрытие (`CoatingSystemRepository::findByLayerCoatingId`), для каждой делает то же.

## Секция 3. Кэш-таблицы

**`coating_system_search`** (1:1 с системой):
- `system_id UUID PK REFERENCES coating_system(id) ON DELETE CASCADE`
- `min_building_time_at_20_minutes INT NULL`
- `max_layer_application_min_temp INT NULL`
- `search_tsvector TSVECTOR NULL`
- Индексы: btree на min/max, GIN на search_tsvector.

**`coating_system_compliance`** — уже существует, оставляется как есть:
- `(system_id, standard, category, durability) PK, FK ON DELETE CASCADE`
- Индекс `(standard, category, durability, system_id)`.

**Ни та ни другая не мапятся в Doctrine ORM XML CoatingSystem** — только через thin repository-классы
в `App\Coatings\Infrastructure\Cache\`:

- `CoatingSystemSearchCacheRepository` — метод `upsert(CoatingSystem $system): void` (INSERT ... ON
  CONFLICT DO UPDATE с runtime-вычислением через методы агрегата) и `delete(string $systemId): void`.
- `CoatingSystemComplianceCacheRepository` — метод `rewrite(CoatingSystem $system,
  ComplianceEvaluator $evaluator): void` (DELETE + INSERT из `$system->complianceMatches($evaluator)`)
  и `delete(string $systemId): void`.

**FTS-документ** (что попадает в tsvector) — собирается в `CoatingSystemSearchCacheRepository` из
публичных геттеров агрегата: `title + description + manufacturer.title каждого слоя + coating.title
каждого слоя + tag.title каждого тега`. Это инфраструктурное решение, не бизнес-логика.

## Секция 4. Command handler-ы

Все mutation-handler-ы становятся тонкими: мутация агрегата + save. Никаких дополнительных
вызовов проектора, никаких инъекций chainValidator/evaluator.

Затрагиваемые handler-ы (убрать из них `chainValidator` из конструктора и `setChainValidator` из
`__invoke`, если есть `searchProjector`-инъекция из моих недавних правок — тоже убрать):

- `CreateCoatingSystemCommandHandler`
- `UpdateCoatingSystemMetadataCommandHandler`
- `AppendLayerCommandHandler`
- `InsertLayerAtCommandHandler`
- `RemoveLayerAtCommandHandler`
- `MoveLayerCommandHandler`
- `UpdateLayerDftCommandHandler`

`UpdateCoatingCommandHandler` — тоже без специальной логики; `Coating::setBase`/`setDftRange`/etc.
сами публикуют `CoatingMutated`, subscriber обрабатывает.

`RemoveCoatingSystemCommandHandler` — кэш чистится каскадом FK.

**Удаляемые файлы**:
- `App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator`
- `App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidatorInterface`
- `App\Coatings\Infrastructure\Projector\CoatingSystemSearchProjector`
- Соответствующие тесты (переписать под subscriber-ы).
- Регистрации в `services.yaml` для удаляемых классов.

## Секция 5. Транзиция

**Текущее состояние test-БД** (после уже накатанных миграций 20260801150000/160000/170000, плюс
возможно 180000):
- Колонки min/max на `coating_system` (лишние).
- В `coating_system_search` только `search_tsvector` (без min/max — их вытащили в 170000).
- Возможно колонка `compliance_matches` JSONB на `coating_system` (лишняя, если 180000 накатана).
- Проектор пишет compliance и tsvector.

**Одна финальная миграция `Version20260801190000`** — приводит схему к «финальному виду»:

```
UP (все действия идемпотентны через IF NOT EXISTS/IF EXISTS):
  # Убрать лишнее с coating_system
  DROP INDEX IF EXISTS idx_cs_min_building
  DROP INDEX IF EXISTS idx_cs_max_app_temp
  ALTER TABLE coating_system
    DROP COLUMN IF EXISTS min_building_time_at_20_minutes,
    DROP COLUMN IF EXISTS max_layer_application_min_temp

  DROP INDEX IF EXISTS idx_cs_compliance_matches
  ALTER TABLE coating_system DROP COLUMN IF EXISTS compliance_matches

  # Вернуть min/max в coating_system_search
  ALTER TABLE coating_system_search
    ADD COLUMN IF NOT EXISTS min_building_time_at_20_minutes INT,
    ADD COLUMN IF NOT EXISTS max_layer_application_min_temp  INT
  CREATE INDEX IF NOT EXISTS idx_css_min_building
    ON coating_system_search (min_building_time_at_20_minutes)
  CREATE INDEX IF NOT EXISTS idx_css_max_app_temp
    ON coating_system_search (max_layer_application_min_temp)
```

Финальный вид `coating_system_search`: `(system_id PK, min_building_time_at_20_minutes,
max_layer_application_min_temp, search_tsvector)` + GIN на tsvector + btree на min/max.

**Backfill** — после миграции разово запустить rebuild-команду
`app:coating-system:rebuild-search-cache` (шаг 6): итерирует все системы, для каждой вызывает
subscriber-логику. Это заполняет кэш из существующих систем.

**Порядок деплоя**:
1. Мерж PR с новым кодом + миграция 190000 + rebuild-команда.
2. Миграция накатывается (перестраивает схему кэша).
3. Ручной запуск rebuild-команды один раз.
4. Дальше event-driven — новые мутации автоматически обновляют кэш.

## Порядок реализации (пошагово, показывать после каждого шага)

1. **Domain events + инфраструктура публикации** — классы `CoatingSystemMutated`, `CoatingMutated`.
   Публикация из сеттеров агрегатов. Юнит-тесты: агрегат после мутации имеет ожидаемое событие в
   pullEvents.
2. **Очистка домена CoatingSystem** — удалить chainValidator (класс + интерфейс + все ссылки в
   handler-ах). Публичные runtime-методы `minBuildingTimeAt20Minutes`, `maxLayerApplicationMinTemp`,
   `complianceMatches(evaluator)`. Приватный `assertLayersAreChainable`. Убрать поля-кэши и их ORM.
   Юнит-тесты домена: методы, инвариант.
3. **Миграция `Version20260801190000`** — идемпотентная. Прогон на локальной test-БД.
4. **Кэш-репозитории** — `CoatingSystemSearchCacheRepository`, `CoatingSystemComplianceCacheRepository`.
   Функциональные тесты в изоляции: `upsert`/`rewrite` работают, каскадное удаление через FK.
5. **Subscribers** — `RefreshCacheOnCoatingSystemMutatedHandler`,
   `RefreshCacheOnCoatingMutatedHandler`. Функциональные тесты: dispatch событий → таблицы
   обновились.
6. **Уборка проектора и старых тестов** — удалить `CoatingSystemSearchProjector` +
   соответствующие тесты, обновить `services.yaml`. Проверить что все тесты зелёные.
7. **Rebuild-команда** `app:coating-system:rebuild-search-cache` (шаг 6 плана 1).
   Функциональный тест: команда наполняет обе таблицы.

## Файлы (сводка)

**Создать**:
- `app/src/Coatings/Domain/Event/CoatingSystemMutated.php`
- `app/src/Coatings/Domain/Event/CoatingMutated.php`
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatch.php` (VO)
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatches.php` (коллекция)
- `app/src/Coatings/Application/Event/RefreshCacheOnCoatingSystemMutatedHandler.php`
- `app/src/Coatings/Application/Event/RefreshCacheOnCoatingMutatedHandler.php`
- `app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php`
- `app/src/Coatings/Infrastructure/Cache/CoatingSystemComplianceCacheRepository.php`
- `app/src/Shared/Infrastructure/Database/Migrations/Version20260801190000.php`
- `app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemSearchCacheCommand.php` (шаг 7)

**Изменить**:
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — удалить chainValidator/поля-кэши, публичные runtime-методы, приватный invariant, публикация событий.
- `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` — публикация `CoatingMutated` в мутирующих сеттерах.
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php` — метод `evaluate` возвращает `ComplianceMatches` (VO-коллекция) вместо голого array.
- `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml` — убрать mapping для min/max/complianceMatches.
- `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php` — метод `findByLayerCoatingId(string $coatingId): array`.
- `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php` — реализация findByLayerCoatingId.
- Все 7 mutation-command-handler-ов CoatingSystem — убрать chainValidator из конструктора и `__invoke`.
- `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php` — никаких дополнительных вызовов (агрегат сам публикует событие).
- `app/config/services.yaml` — убрать регистрации удаляемых классов.

**Удалить**:
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidator.php`
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidatorInterface.php`
- `app/src/Coatings/Infrastructure/Projector/CoatingSystemSearchProjector.php`
- `app/tests/Functional/Coatings/Infrastructure/Projector/CoatingSystemSearchProjectorTest.php`
  (переписать под subscriber-ы в `app/tests/Functional/Coatings/Application/Event/`).

## Что вне scope

- Compare tray для систем — отдельная задача (после плана 2).
- UI поиск/фасеты — план 2 (`plan-2-query-ui.md`).
- Обработка `TagRenamed` (переименование тега влияет на tsvector всех систем с этим тегом) —
  вернёмся когда встанет реально; сейчас теги пользователь только добавляет/удаляет.

## Cross-ref

- Предыдущая версия (устаревшая): `plan-1-projector.md` — заменяется этим планом.
- Следующий план: `plan-2-query-ui.md` — потребители кэш-таблиц (Finder + UI).
