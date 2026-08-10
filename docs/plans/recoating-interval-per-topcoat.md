# План: корректный минимальный интервал перекрытия под конкретный топкоат

## Задача

Покрытие должно однозначно считать минимальный интервал перекрытия для **конкретного
покрытия, которое наносят поверх**, а не по общему дефолту. Пример: поверх покрытия
эпоксид можно наносить через 1 ч, а полиуретан — через 3 ч. Если у покрытия задано
отдельное правило под (среда, основание топкоата) — использовать его; иначе провалиться
на общее правило среды; иначе — на общий default покрытия.

## Диагноз (что не так сейчас)

- Дерево `RecoatingIntervalTree` умеет хранить ветки `default → среда(EnvironmentType) →
  основание топкоата(CoatingBase)`, а `find(env, base)` уже делает нужный fallback
  «глубже, где обрывается — берём default найденного уровня».
- Но фактический расчёт идёт **мимо**: `Coating::interpolatedMinRecoatMinutesAt20($dft)`
  читает жёстко `minRecoatingInterval->default->getPoint(20)` — корневую плоскую серию,
  игнорируя и среду, и основание следующего слоя.
- `CoatingSystem::minApplicationTimeAt20Minutes()` суммирует интервалы по слоям, но
  **не смотрит на следующий слой** (топкоат) вообще.
- Готовый API `minRecoatingFor(env, topcoat)` в проде никто не звал — мёртвая ветка.
  Задача его оживляет.

## Принятые решения

1. **Среду храним на системе.** `CoatingSystem` получает поле `environment: EnvironmentType`.
   Единичное значение времени сборки остаётся осмысленным, кэш/фасет поиска не меняем.
2. **DFT-пересчёт оставляем** — интервал масштабируется под фактическую толщину слоя через
   `RecoatingInterpolationModel` (как сейчас).
3. **Температура — параметр, дефолт +20 °C** на доменном методе. Системная сумма считает при +20.
4. **Данных в БД нет** → миграция без backfill, `environment` сразу NOT NULL. Схему правим свободно.
5. **Fallback по температуре:** используется самая глубокая подходящая серия из `find(env, base)`;
   если в ней нет точки при нужной T — интервал считается неизвестным (`null`), молчаливого
   отката на менее специфичное правило нет. Для T=20 инвариант хранения гарантирует точку в
   корневом default, поэтому обычный (плоский) путь всегда считается.
6. **Интерполированные по T точки допустимы** (`isCalculated=true`) — вводим предикат
   `TimeAtTemperature::hasPositiveDuration()`. Старый `isExplicitPositiveDuration()` остаётся
   только для инварианта хранения (`assertMinRecoatingHasBasePointAt20`).
7. **Читаемость** (принцип 3 CLAUDE.md): методы — говорящие, без гроздей условий; правило
   применяет владелец данных и возвращает готовый результат.

## Изменения по файлам

### Домен — ядро

**`Coating/TimeAtTemperature.php`**
- Добавить предикат `hasPositiveDuration(): bool` (`null !== timeInMinutes && timeInMinutes > 0`,
  без оглядки на `isCalculated`). `isExplicitPositiveDuration()` не трогаем.

**`Coating/Coating.php`** — единственный метод считает всё: среду, основание топкоата, температуру, толщину.
- Удалить `interpolatedMinRecoatMinutesAt20(int $layerDft)` и `minRecoatingPointAt(...)` (сливаются сюда).
- Переписать `minRecoatingFor` в единственный расчётный метод:
  ```php
  /**
   * Минимальный интервал перекрытия ЭТОГО покрытия перед нанесением поверх него $topcoat,
   * в среде $env, при температуре $temperature (по умолчанию +20 °C).
   *
   * $actualDft — фактическая толщина, с которой нанесено САМО ЭТО покрытие (нижний слой);
   * интервал пересчитывается с эталонной tdsDft на неё. null → tdsDft (без пересчёта).
   * Это толщина именно этого покрытия, а не топкоата.
   *
   * Null — если для температуры длительность неизвестна (вне диапазона точек / unknown / unlimited).
   */
  public function minRecoatingFor(
      CoatingBase $topcoat,
      EnvironmentType $env,
      ?int $actualDft = null,
      int $temperature = 20,
  ): ?int {
      $wait = $this->minRecoatingInterval->find($env->value, $topcoat->value)->series->getPoint($temperature);
      if (null === $wait || !$wait->hasPositiveDuration()) {
          return null;
      }

      return $this->recoatingInterpolationModel->interpolate(
          sourceDft: $this->dftRange->tdsDft,
          targetDft: $actualDft ?? $this->dftRange->tdsDft,
          sourceMinutes: $wait->timeInMinutes,
      );
  }
  ```
  `find(env, base)` даёт fallback вглубь; `getPoint` — интерполяцию по T; `interpolate` — пересчёт по толщине.
- `maxRecoatingFor` / `maxRecoatingPointAt` в этой задаче **не трогаем** (нет потребителей; у max иная
  семантика `0` = «без ограничения»).

**`CoatingSystem/CoatingSystem.php`**
- Поле `private EnvironmentType $environment;` + конструктор-параметр, `getEnvironment()`,
  `setEnvironment()` (raise `CoatingSystemMutated` + `touch`).
- Переписать `minApplicationTimeAt20Minutes()` на пары соседних слоёв: для каждой пары
  `[нижний, верхний]` брать `нижний->getCoating()->minRecoatingFor(верхний.base, $this->environment,
  нижний.dft)`. Ввести приватный генератор `adjacentLayerPairs(): \Generator` — читается как
  «нижний ждёт перед нанесением верхнего». Поведение по количеству слагаемых прежнее (N−1),
  но теперь учитывается топкоат и среда.

### ORM / миграция

**`.../ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml`**
- `<field name="environment" type="string" length="32" nullable="false"
  enum-type="App\Coatings\Domain\Aggregate\Coating\EnvironmentType"/>` (зеркалит `substrate`).

**`Shared/Infrastructure/Database/Migrations/Version*.php`** (новая, идемпотентная)
- `ALTER TABLE coating_system ADD COLUMN IF NOT EXISTS environment VARCHAR(32) NOT NULL DEFAULT 'atmospheric';`
  Данных нет, backfill не нужен; default оставляем для идемпотентности.

### Application / Infrastructure (обвязка формы)

- **`CreateCoatingSystemCommand`** + **`UpdateCoatingSystemMetadataCommand`**: добавить
  `EnvironmentType $environment`.
- **`CreateCoatingSystemCommandHandler`**: прокинуть env в конструктор `CoatingSystem`.
- **`UpdateCoatingSystemMetadataCommandHandler`**: `$system->setEnvironment($cmd->environment)`.
- **`CoatingSystemMapper`**:
  - `buildCommandFromInputData`: `EnvironmentType::from($input['environment'])` → в обе команды.
  - `buildInputDataFromDto`: добавить `environment` (в null-ветке — `''`).
  - `getValidationCollection`: `environment` → `NotBlank` + `Choice` по `EnvironmentType::cases()`.
- **`CoatingSystemDTO`**: поля `environment` + `environmentTitle`.
- **`CoatingSystemDTOTransformer::fromEntity`**: заполнить из `$system->getEnvironment()`.
- **`CoatingSystem/AddAction.php`, `UpdateAction.php`**: в оба `render` добавить
  `'environments' => EnvironmentType::cases()`.
- **`templates/cabinet/coating/coating_system/form.html.twig`**: `<select name="environment">`
  копией блока `substrate` (итерация `environments`, preselect `inputData.environment`,
  для Add дефолт — Atmospheric). Разметку копируем 1-в-1, новых классов не вводим.

### Фильтр списка систем по среде + чип (одиночный выбор)

Среда — колонка `cs.environment`, фильтруется прямо на основной таблице (как `substrate`),
кэш-таблицу `coating_system_search` не трогаем. Чип **одиночный** — по образцу фасета «Стандарт»
(single-select radio), а не «Подложка» (multi): выбрана одна среда либо ни одной.

- **`CoatingSystemsFilter`**: добавить `?EnvironmentType $environment = null`.
- **`CoatingSystemListRequestMapper`**: `environment: EnvironmentType::tryFrom((string) $request->query->get('environment', ''))`.
- **`CoatingSystemFinder`**: `applyEnvironment()` → `cs.environment = :environment` (одно значение),
  вызвать в `find()`.
- **`CoatingSystemListViewFactory`**: echo `environment`; `environmentOptions => EnvironmentType::cases()`;
  учесть `environmentActive` в `activeFacetsCount`.
- **`templates/.../coating_system/list.html.twig`**: зеркалить фасет «Стандарт» (single) в трёх местах:
  1. chip-row: не активна → дропдаун с radio `name="environment"` + «Применить» и мобильный триггер
     `#chipFilterEnvironment`; активна → одиночный чип с remove-URL (`environment: null`);
  2. мобильный offcanvas `#chipFilterEnvironment`;
  3. offcanvas «Все фильтры»: строка активного фильтра + секция с radio-опциями.
  Плюс `environmentActive` / `environmentSummary` в шапке шаблона. Разметку копируем 1-в-1
  из блока «Стандарт», меняем только имя параметра, тексты и `title()`.

## Тесты

- **`Unit/.../Coating/TimeAtTemperatureTest.php`**: `hasPositiveDuration()` — duration>0 / 0 / null /
  isCalculated+положительное.
- **`Unit/.../Coating/CoatingTest.php`** (`minRecoatingFor`):
  - плоское дерево → fallback на default;
  - ветка среды без основания → значение среды;
  - ветка среда+основание: EP-топкоат = 60, PUR-топкоат = 180 (тот самый кейс);
  - интерполяция по температуре между точками (кастомный `$temperature`);
  - unknown/unlimited/вне диапазона → `null`;
  - толщина: `actualDft = null` → эталон `tdsDft` (identity, без пересчёта); `actualDft` ≠ tdsDft → пересчёт (LINEAR/STEP).
- **`Unit/.../CoatingSystem/CoatingSystemTest.php`**:
  - обновить хелперы `newSystem`/инлайн-конструкторы (строки 266/335/383) под env-параметр;
  - существующий `test_min_building_time_sums_interpolated_intervals_except_top` остаётся 192
    (плоское дерево + любая среда → default);
  - новый кейс: нижнее покрытие с веткой среда+основание — сумма выбирает интервал под
    основание верхнего слоя (EP→60 vs PUR→180).
- **Functional**: create/update системы сохраняет и читает `environment` (round-trip).
- **Mapper round-trip**: `environment` переживает `build → decompose → build`.

## Вне рамок этого плана

- **Наполнение систем.** Незакоммиченное (`ImportCoatingSystemsCommand`, `ImportSupport`,
  `Console/Resources/`, его тесты, `docs/plans/import-coating-systems.md`) — правим **после**
  основного: проставить `environment` по импортируемым системам. Отдельный проход.
- **Рефактор размера `Coating`** (518 строк) — отдельный план (Plan 2), чистый рефактор без
  изменения поведения: вынести валидатор температурного диапазона и механику мутации дерева.

## Порядок реализации (по шагам, с показом после каждого)

1. `TimeAtTemperature::hasPositiveDuration()` + тест.
2. `Coating::minRecoatingFor()` — единственный расчётный метод (удалить `interpolatedMinRecoatMinutesAt20`
   и `minRecoatingPointAt`) + тесты.
3. `CoatingSystem`: поле env + переписанный `minApplicationTimeAt20Minutes` + тесты.
4. ORM + миграция.
5. Обвязка формы (команды, хендлеры, mapper, DTO, transformer, actions, twig) + тесты.
6. Прогон тестов затронутых контекстов, пересборка ассетов (`yarn dev`).
7. Потом — наполнение систем (env в импорте).
