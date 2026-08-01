# Поиск систем покрытий — План 1: наполнение read-модели

Первый из двух деплоев. Расширяет проекцию систем до полноценной read-модели поиска (sum_min_recoat_20, max_application_min_temp, ts_vector, теги), но пользователь ещё ничего нового не видит: старый list продолжает читать существующие поля через `ListCoatingSystemsQuery`, страница `search-by-compliance` остаётся живой.

Второй план (`plan-2-query-ui.md`) переключает чтение на новую модель, добавляет FTS/фасеты/typeahead/infinite-scroll в UI и вычищает `SearchByCompliance`.

## Зачем разделено

Наполнить БД и переключить чтение — независимые операции. При разделении:
- Проектор можно погонять на реальных данных до того, как UI на него завязан.
- Rebuild-команда прогонит все существующие системы в проектор без риска сломать пользователю list.
- Второй деплой опирается на факт, что все системы уже перепроектированы, и не тащит миграционную логику.

## Что входит в план 1

**Domain**:
- Переименование агрегата `CoatingTag` в `Tag` (в рамках контекста `Coatings`). Заодно переименовываются `CoatingTagRepositoryInterface`, `CoatingTagRepository`, `CoatingTagFinder`, `CoatingTagDTO`, `CoatingTagDTOTransformer` — вся цепочка. Таблицы БД (`coating_tag`, `coating_coating_tag`) НЕ переименовываются — ORM XML указывает старые имена. Плюс новая many-to-many `Tag ↔ CoatingSystem`.
- Новое перечисление `RecoatingInterpolationModel` (LINEAR, STEP) как атрибут покрытия. Дефолт — LINEAR. Метод `interpolate(int $sourceDft, int $targetDft, int $sourceMinutes): int` на самом enum. У `Coating` — новое поле `recoatingInterpolationModel` с дефолтом LINEAR.
- **Инвариант: default DryingTimeSeries в RecoatingIntervalTree обязан содержать точку при 20°C с валидным `time_in_minutes` (>0)**. Без этой точки интерполировать по толщине слоя системы не с чего, поэтому такое покрытие не имеет смысла. Проверка живёт в конструкторе `RecoatingIntervalTree` (или `DryingTimeSeries` default-корне) — при нарушении бросает `AppException` с человекочитаемым сообщением. Автоматически действует при любом create/update покрытия.

**Read-model**:
- Отдельная новая таблица `coating_system_search` — **одна строка на систему** (PK system_id). Существующая `coating_system_compliance` остаётся чисто compliance-таблицей: `(system_id, standard, category, durability)`. Причина разделения: у системы без совпадений с ISO/NORSOK compliance-строк нет, и если бы search-поля жили в compliance — такая система не находилась бы FTS. Плюс избежали бы дублирования одного tsvector на все compliance-строки одной системы.
- Новые колонки:
  - `sum_min_recoat_20_minutes int null` — сумма мин.интервалов при 20°C по слоям кроме верхнего. **Placeholder-логика в этом плане**: `SUM(RECOATING_AT_20C(coating.minRecoatingInterval))` без интерполяции по толщине слоя. Интерполяция вклинивается позднее (см. открытый пункт).
  - `max_application_min_temp int null` — `MAX(coating.applicationMinTemp)` по всем слоям.
  - `search_tsvector tsvector` — ts_vector-документ по title, description, названиям производителей слоёв, названиям тегов (по образцу `CoatingFinder`).
- Индексы: `GIN` на `search_tsvector`, btree на `substrate`, `sum_min_recoat_20_minutes`, `max_application_min_temp`.

**Проектор**:
- `CoatingSystemComplianceProjector` расширяется до `CoatingSystemSearchProjector`. Считает все новые поля при `postPersist`/`postUpdate` системы или её слоёв.
- Rebuild-команда `RebuildCoatingSystemComplianceCommand` расширяется до `RebuildCoatingSystemSearchIndex` (переименование + учёт новых полей). Прогоняет все системы после миграции.

**Форма/маппер**:
- `CoatingSystem` агрегат: поля/методы работы с тегами (`addTag`, `removeTag`, `getTags`).
- `CoatingSystemMapper` — приём `tagIds[]` из формы в DTO. Форма редактирования системы получит поле «Теги» в плане 2 (сейчас без UI-правок).

## Что НЕ входит в план 1

- Новый Query/Handler/Filter/Finder для поиска — идёт в план 2.
- Изменения главного шаблона `list.html.twig`, чипов, typeahead, infinite scroll — план 2.
- Уборка `SearchByCompliance` — план 2 (пока живёт как есть).
- Линейная интерполяция мин.интервала перекрытия по фактической толщине слоя — открытый пункт, вклинивается в проектор отдельным подшагом (см. ниже).

## Изменения по слоям

### Domain

Создать:
- `App\Coatings\Domain\Aggregate\Tag\Tag` (переименовать из `App\Coatings\Domain\Aggregate\CoatingTag\CoatingTag`).
- `App\Coatings\Domain\Aggregate\Tag\TagRepositoryInterface` (переименовать).
- `App\Coatings\Domain\Aggregate\Coating\RecoatingInterpolationModel` (enum, метод `interpolate`).

Изменить:
- `App\Coatings\Domain\Aggregate\Coating\Coating` — новое поле `recoatingInterpolationModel`, конструктор/сеттер, геттер.
- `App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem` — коллекция `Tag[]`, методы `addTag`, `removeTag`, `getTags`.

### Application

Изменить:
- `CoatingDTOTransformer` — включить `recoatingInterpolationModel` в DTO.
- `CoatingSystemDTOTransformer` — включить теги в DTO.
- Все команды/хендлеры покрытия, где создаётся/обновляется `Coating`, — пробросить `recoatingInterpolationModel` (по умолчанию LINEAR).
- CoatingSystem-команды `CreateCoatingSystem` / `UpdateCoatingSystemMetadata` — принять `tagIds[]` (миграция полей DTO).

### Infrastructure

Создать:
- `App\Coatings\Infrastructure\Projector\CoatingSystemSearchProjector` (переименовать + расширить `CoatingSystemComplianceProjector`).
- `App\Coatings\Infrastructure\Console\RebuildCoatingSystemSearchIndex` (переименовать + расширить `RebuildCoatingSystemComplianceCommand`).
- ORM XML для новой связи `CoatingSystem ↔ Tag`: `CoatingSystem.CoatingSystem.orm.xml` — добавить `<many-to-many field="tags" target-entity="Tag">` с join-таблицей `coating_system_tag`.

Изменить:
- ORM XML для `Tag` — оставить table name `coating_tag` (не переименовываем).
- ORM XML для `Coating.Coating.orm.xml` — новое поле `recoatingInterpolationModel` типа string, mapping как enum.

Удалить: ничего в этом плане.

### БД (миграция)

Одна миграция `VersionYYYYMMDDhhmmss.php` в `app/src/Shared/Infrastructure/Database/Migrations/`:

```
1. ALTER TABLE coating_system_compliance
     ADD COLUMN IF NOT EXISTS sum_min_recoat_20_minutes int null,
     ADD COLUMN IF NOT EXISTS max_application_min_temp int null,
     ADD COLUMN IF NOT EXISTS search_tsvector tsvector;

2. CREATE INDEX IF NOT EXISTS idx_css_search_tsv ON coating_system_compliance USING GIN (search_tsvector);
3. CREATE INDEX IF NOT EXISTS idx_css_substrate ON coating_system_compliance (substrate);
4. CREATE INDEX IF NOT EXISTS idx_css_sum_recoat ON coating_system_compliance (sum_min_recoat_20_minutes);
5. CREATE INDEX IF NOT EXISTS idx_css_max_temp ON coating_system_compliance (max_application_min_temp);

6. CREATE TABLE IF NOT EXISTS coating_system_tag (
     coating_system_id uuid NOT NULL,
     tag_id uuid NOT NULL,
     PRIMARY KEY (coating_system_id, tag_id),
     FOREIGN KEY (coating_system_id) REFERENCES coating_system(id) ON DELETE CASCADE,
     FOREIGN KEY (tag_id) REFERENCES coating_tag(id) ON DELETE CASCADE
   );

7. ALTER TABLE coating
     ADD COLUMN IF NOT EXISTS recoating_interpolation_model varchar(32) NOT NULL DEFAULT 'LINEAR';
```

Все SQL идемпотентные.

## Порядок шагов реализации

Каждый шаг → показ пользователю → апрув → следующий.

1. **Переименование `CoatingTag → Tag`** — PHP-неймспейсы, файлы, тесты. ORM XML указывает старые table names. Прогон unit+functional тестов покрытия.
2. **`RecoatingInterpolationModel` enum** — только сам enum + юнит-тест граничных случаев интерполяции. Поле в агрегате `Coating` откладывается до шага 4, где идёт синхронно с миграцией и ORM XML.
3. **`Tag ↔ CoatingSystem` many-to-many** — ORM XML mapping (файл появится, но БД без таблицы до шага 4), методы `addTag`/`removeTag`/`getTags` на агрегате, `CoatingSystemDTO`, `CoatingSystemDTOTransformer`, `CoatingSystemMapper`, команды. Функциональные тесты — вместе с шагом 4 (сейчас БД без таблицы, они бы упали).
4. **Миграция БД + подключение полей к агрегатам** — единый Version-файл: (a) ALTER `coating_system_compliance` под новые колонки; (b) CREATE `coating_system_tag`; (c) ALTER `coating` ADD `recoating_interpolation_model`. Здесь же: ORM XML для нового поля покрытия, поле в `Coating` агрегате, DTO/Mapper/CommandHandlers пробрасывают модель интерполяции. Функциональные тесты create/update покрытия с явной моделью + тесты команд системы с тегами.
4а. **Инвариант «мин.интервал при 20°C обязателен»** — проверка в конструкторе `RecoatingIntervalTree`/`DryingTimeSeries` default-корня. Юнит-тесты граничных случаев (нет точки при 20°C, точка есть но `time_in_minutes = 0`, точка есть но `time_in_minutes = null`). Проверить существующие юнит-тесты на подстройку под новый инвариант (могут потребоваться правки фикстур в тестах покрытия).
5. **Расширение проектора** — `CoatingSystemSearchProjector`: `max_application_min_temp`, `sum_min_recoat_20_minutes` (без интерполяции), `search_tsvector`. Функциональный тест проектора.
6. **Rebuild-команда** — `RebuildCoatingSystemSearchIndex`. Функциональный тест. Прогон на локальной БД.

## Открытый пункт: линейная интерполяция интервала перекрытия

Обсудить и вклинить в шаг 5 (или отдельным подшагом 5а):

- Мин.интервал перекрытия у покрытия хранится в `RecoatingIntervalTree` и указан для его `tdsDft` (из `DftRange`). В системе слой применяется с толщиной `layerDft`. Нужна линейная интерполяция от `(tdsDft, minutes)` до фактической `layerDft`.
- Реализация в PHP-проекторе (не в DQL-функции): для каждого слоя системы получить `Coating.minRecoatingInterval` и `Coating.dftRange.tdsDft`, применить `Coating.recoatingInterpolationModel` (по умолчанию LINEAR).
- Edge cases: `layerDft` вне диапазона `DftRange`, значение null, значение 0.

Точную формулу и обработку edge cases обсудим перед началом шага 5.

## Тесты

**Unit** (`app/tests/Unit/Coatings/...`):
- `Domain/Aggregate/Tag/TagTest` (переименовать из `CoatingTagTest`).
- `Domain/Aggregate/Coating/RecoatingInterpolationModelTest` — новые тесты: LINEAR interpolate между двумя точками, STEP (nearest), null-инпут.
- `Domain/Aggregate/CoatingSystem/CoatingSystemTest` — добавить методы `addTag`, `removeTag`, `getTags` с граничными случаями.

**Functional** (`app/tests/Functional/Coatings/...`):
- Существующие `ListCoatingSystemsQueryHandlerTest`, `SearchCoatingSystemsByComplianceQueryHandlerTest` — не тронуты, продолжают проходить (переключение чтения — в плане 2).
- `Infrastructure/Projector/CoatingSystemSearchProjectorTest` — расширить существующий `CoatingSystemComplianceProjectorTest`: пересчёт новых полей, ts_vector, rebuild.
- `Infrastructure/Console/RebuildCoatingSystemSearchIndexTest` — переименовать и расширить `RebuildCoatingSystemComplianceCommandTest`.

## Файлы (сводка)

**Создать/переименовать**:
- `app/src/Coatings/Domain/Aggregate/Tag/Tag.php` (переименовать из `CoatingTag`).
- `app/src/Coatings/Domain/Aggregate/Tag/TagRepositoryInterface.php` (переименовать).
- `app/src/Coatings/Infrastructure/Repository/TagRepository.php` (переименовать из `CoatingTagRepository`).
- `app/src/Coatings/Infrastructure/Search/TagFinder.php` (переименовать из `CoatingTagFinder`).
- `app/src/Coatings/Application/DTO/Tags/TagDTO.php`, `TagDTOTransformer.php` (переименовать из `CoatingTags/`).
- `app/src/Coatings/Domain/Aggregate/Coating/RecoatingInterpolationModel.php` (новый enum).
- `app/src/Coatings/Infrastructure/Projector/CoatingSystemSearchProjector.php` (переименовать из `CoatingSystemComplianceProjector`, расширить).
- `app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemSearchIndex.php` (переименовать из `RebuildCoatingSystemComplianceCommand`, расширить).
- `app/src/Shared/Infrastructure/Database/Migrations/VersionYYYYMMDDhhmmss.php` (одна миграция).

**Изменить**:
- `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` — поле `recoatingInterpolationModel`.
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — коллекция тегов + методы.
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php`, `CoatingDTOTransformer.php` — новое поле.
- `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTO.php`, `CoatingSystemDTOTransformer.php` — теги.
- `app/src/Coatings/Application/UseCase/Command/CreateCoating/*`, `UpdateCoating/*` — пробрасывают модель интерполяции.
- `app/src/Coatings/Application/UseCase/Command/CreateCoatingSystem/*`, `UpdateCoatingSystemMetadata/*` — принимают `tagIds[]`.
- `app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php` — `tagIds[]`.
- `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml`, `CoatingSystem.CoatingSystem.orm.xml`, ORM XML для `Tag`.

**Удалить**: ничего.

## Cross-ref

- План 2: `plan-2-query-ui.md` — читает эту read-модель и переключает пользователя на новый поиск.
