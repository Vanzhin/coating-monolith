# Деплой 2: цвет слоя системы — обязательный (убрать nullable-легаси)

Связанный план: `fill-system-layer-colors-1.md` (бэкфил цветов). **Деплоить только ПОСЛЕ того, как Деплой 1 уехал на прод** — иначе на проде остаются слои с `color_id IS NULL`, миграция NOT NULL и домен упадут. Код пишем на отдельной ветке от `main`, но не мерджим/не деплоим до прода Деплоя 1.

## Задача

Слой системы (`CoatingSystemLayer`) не должен собираться без цвета. Сейчас `?Color $color` nullable «ради легаси», `assertColorAllowed` пропускает `null`. После бэкфила легаси-слоёв без цвета не остаётся — закрываем дыру инвариантом + NOT NULL в БД.

## Решения по развилкам (согласовано)

1. Тестовые команды `AppendLayerCommand`/`InsertLayerAtCommand` (в приложении не вызываются, только тесты) — добавить **обязательный** `colorId`, резолвить как в Create/Replace. Не удалять (пригодятся для будущего AJAX-редактора).
2. Обе импорт-команды — **НЕ чиним**, одноразовые, под выпил:
   - `ImportCoatingSystemsCommand` — теста нет, CI не трогает.
   - `ImportConclusionsCommand` — строит систему через `CreateCoatingSystemCommand` без `colorId` → после рефактора создание падает. Единственный тест `ImportConclusionsCommandTest` (проверял создание системы) **удалён** — команда деприкейтнута, чинить не будем.

   Команду бэкфила `FillSystemLayerColorsCommand` (Деплой 1) **удаляем в этом деплое**: под обязательным цветом её null-ветка мёртвая (getColor теперь `Color`). Жизненный цикл: добавлена и прогнана на проде в Деплое 1 → удаляется в Деплое 2, который катится после. Ветка Деплоя 2 стоит поверх ветки Деплоя 1 — это корректно (add в D1, remove в D2).
3. Миграция — полагается, что Деплой 1 отработал на проде. Пересоздать FK без `ON DELETE SET NULL` + `color_id SET NOT NULL`. Если остались `NULL` — падать с внятным сообщением «сначала прогони `app:coating-system:fill-layer-colors`». Backfill-политику в SQL не дублируем.
4. Read-path/DTO ужесточить: снять мёртвую null-ветку в `CoatingSystemDTOTransformer`, `CoatingSystemLayerDTO::colorId/colorName/colorLabel` → non-null (`colorRal/colorHex` остаются nullable — сам `Color.ral` nullable). Twig-гарды оставить (безвредны).

Политику «первый цвет / серый» никуда, кроме Деплоя 1, не тащим: Create/Replace берут цвет из формы (она требует `colorId` через `Assert\NotBlank`).

## Радиус (по карте кода)

### Домен
- `CoatingSystemLayer.php`: конструктор `?Color $color = null` → `Color $color` (убрать легаси-комментарий); `getColor(): ?Color` → `getColor(): Color`; `assertColorAllowed(?Color, Coating)` → `assertColorAllowed(Color, Coating)`, убрать раннюю ветку `null === $color` (оставить `isTintable` + проверку членства).
- `CoatingSystem.php`: `appendLayer(Coating,int,?Color $color=null)` → `Color $color` (обязательный, без дефолта); `insertLayerAt(...,?Color $color=null)` → `Color $color`; `replaceLayers` shape `color?: ?Color` → `color: Color`, убрать `?? null` (строка 421).

### Application
- `CreateCoatingSystemCommandHandler` (57-66): пустой/отсутствующий `colorId` → кинуть `AppException` (сейчас молча `null`). Резолв обязателен.
- `ReplaceLayersCommandHandler::resolveColor` (55-67): `?string`→`Color` (не `?Color`), пустой `colorId` → `AppException`.
- `AppendLayerCommand`/`InsertLayerAtCommand`: добавить `public string $colorId`. Их хендлеры: резолв цвета (findOneById, throw если пусто/не найдено) и передать в `appendLayer/insertLayerAt`.
- Команды-DTO shape (`CreateCoatingSystemCommand`, `ReplaceLayersCommand`): `colorId?: ?string` → `colorId: string`.
- `CoatingSystemMapper::layersFromInput` (82-87) + докблок (69): цвет обязателен (валидатор `Assert\NotBlank` на `colorId` уже стоит, 184). `'' → null` больше не нужно; докблок дополнить `colorId`.

### Infrastructure
- ORM `CoatingSystem.CoatingSystemLayer.orm.xml` (строка 31): join-column `color_id` `nullable="true"` → `"false"`.
- Новая миграция `Version<ts>.php`: (a) guard — если `SELECT count(*) ... WHERE color_id IS NULL > 0` → бросить с сообщением про Деплой 1; (b) `DROP CONSTRAINT fk_csl_color` и пересоздать FK без `ON DELETE SET NULL` (RESTRICT); (c) `ALTER COLUMN color_id SET NOT NULL`. Идемпотентно (`IF EXISTS`/`pg_constraint`). `down()` — обратно nullable + FK `ON DELETE SET NULL`.

### Read-path
- `CoatingSystemDTOTransformer` (98-105): снять `if (null !== $color)` — цвет всегда есть.
- `CoatingSystemLayerDTO` (21-27): `colorId/colorName/colorLabel` non-null; `colorRal/colorHex` — nullable.

### Тесты (~30 мест, полагаются на легаси-`null`)
- Основное — `Unit/.../CoatingSystem/CoatingSystemTest.php`: все `appendLayer($c,$dft)` / `replaceLayers([['coating'=>..,'dft'=>..]])` без цвета → дать валидный цвет. Паттерн подготовки: `new Color(...)` + `$coating->applyColorScheme(false, $color)` + передать тот же `$color` (см. строки 507-511). **Удалить** `test_append_layer_without_color_is_accepted_for_legacy` (539-544).
- Functional-фикстур-трейт `CoatingSystemLayerTestFixtureTrait:92` (`appendLayer($coating, 80)`) — централизует много layer-тестов, поправить там → закрывает пачку.
- Остальные unit/functional из карты (DTOTransformerTest, Iso12944EvaluatorTest, Sp28EvaluatorTest, Operating-temp, Update/Remove/Append/Insert/Move-Layer, Query-хендлеры, Repository, Cache, Suggest, Finder, RebuildSearchCache, CertifiedSystemFreeze) — дать валидный цвет в каждом вызове построения слоя.
- Тестовые покрытия обычно без палитры и не tintable → `assertColorAllowed` бросит. Готовить цвет как в паттерне выше (палитра покрытия ИЛИ `applyColorScheme(true)` + любой цвет).

## Порядок реализации

1. Домен (`CoatingSystemLayer`, `CoatingSystem`) — ядро, от него всё зависит.
2. Application (хендлеры, команды, маппер).
3. ORM + миграция.
4. Read-path/DTO.
5. Тесты — привести к зелёному (unit на хосте, functional в контейнере).

## Проверка

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem
./run check phpstan
./run check functional   # эфемерная test_db
cd app && bin/console doctrine:migrations:migrate -n   # на dev с уже забэкфиленной БД
```

## Предусловие деплоя

`SELECT count(*) FROM coating_system_layer WHERE color_id IS NULL` = 0 на проде (Деплой 1 отработал). Иначе миграция и домен падают.
