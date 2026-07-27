# SurfaceTreatment — дизайн

Дата: 2026-07-27
Статус: спека одобрена (диалог с автором), план реализации — отдельный документ.
Родительская фича: `feature/coating-system` (PR #1).

## Цель

Заменить value-object `SurfacePreparation` (свободный `grade`/`description`/`?standard`) на полноценный справочник `SurfaceTreatment` с CRUD-интерфейсом. Одновременно закрыть UX-долг: пользователь никогда не вводит UUID, всегда выбирает из списка или через autocomplete.

Итоговые сценарии:
- Админ или пользователь заводит в справочнике «Sa 2 / ГОСТ Р ИСО 8501-1 / углеродистая сталь».
- Автор системы выбирает подготовку из dropdown, отфильтрованного по substrate системы.
- Если нужного варианта нет — прямо в форме системы жмёт «+ Создать», модалка → сохранение → сразу выбирается.
- Пользователь так же выбирает Coating для слоя из dropdown, а не вводит UUID.

## Границы

- Живёт в существующем bounded context `Coatings/` (новый агрегат внутри).
- Работа ведётся на той же ветке `feature/coating-system`. PR #1 расширяется, отдельного PR не создаём.
- В БД `feature/coating-system` пока 0 записей `coating_system`, миграция FK-переключения делается «на месте», без бэкапа.

## Модель

### `SurfaceTreatment` (новый агрегат-справочник, CRUD)

| Поле | Тип | Обязательно | Пример |
|---|---|---|---|
| `id` | `Uuid` (readonly) | да | — |
| `description` | `string` (1..2000) | да | «При осмотре без применения увеличительных приборов...» |
| `code` | `?string` (0..30) | нет | `Sa 2` |
| `standardCode` | `?string` (0..100) | нет | `ГОСТ Р ИСО 8501-1` |
| `substrateScope` | `list<Substrate>` (min 1) | да | `[STEEL_CARBON]` |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | да | audit |

**Инварианты (VO-уровня, в сеттерах агрегата):**
- `description`: не пустая, ≤ 2000.
- `code`: если задан — не пустой, ≤ 30.
- `standardCode`: если задан — не пустой, ≤ 100.
- `substrateScope`: минимум 1 элемент.

**Уникальность в БД (partial unique index):**
```sql
CREATE UNIQUE INDEX uniq_surface_treatment_code_std
  ON surface_treatment (code, standard_code)
  WHERE code IS NOT NULL AND standard_code IS NOT NULL;
```
Тот же `code` под другим `standardCode` — разные записи. Обе `NULL` — уникальность не применяется, «Обмыв водой» может дублироваться и это ok (модерация вручную).

### `CoatingSystem.surfaceTreatment: SurfaceTreatment` (было VO, стало FK)

- **Обязательное поле.** Заменяет `surfacePreparation: SurfacePreparation` VO.
- ORM: `ManyToOne` к `SurfaceTreatment`, `on-delete="RESTRICT"` — нельзя удалить treatment, пока хотя бы одна система на него ссылается.
- Инвариант при `setSurfaceTreatment(SurfaceTreatment $t)` и `setSubstrate(Substrate $s)`:
  ```
  system.substrate ∈ treatment.substrateScope
  ```
  Иначе `AppException`: «Подготовка «{code|description}» применима только к [{substrateScope}], а в системе выбрана {substrate}.»

### Удаления

- Value object `SurfacePreparation` — удаляем.
- DBAL type `SurfacePreparationType` — удаляем.
- Колонки `surface_preparation_grade`, `surface_preparation_description`, `surface_preparation_standard` в `coating_system` — DROP.
- Добавляем колонку `surface_treatment_id UUID NOT NULL REFERENCES surface_treatment(id) ON DELETE RESTRICT`.
- Существующие тесты Task 2 (`SurfacePreparationTest`) — удаляем.
- Обновляем все фабрики CoatingSystem в тестах (Task 4, 7, 8, 11, 12, 15, 17).

## CRUD

Расположение файлов — по паттерну `Coatings/.../CoatingSystem/`:

```
Coatings/Domain/Aggregate/SurfaceTreatment/
  SurfaceTreatment.php
Coatings/Domain/Repository/
  SurfaceTreatmentRepositoryInterface.php
  SurfaceTreatmentsFilter.php   (title-like, substrate)
Coatings/Infrastructure/Repository/
  SurfaceTreatmentRepository.php
Coatings/Infrastructure/Database/ORM/Aggregate/
  SurfaceTreatment.SurfaceTreatment.orm.xml
Coatings/Infrastructure/Database/DBAL/
  SubstrateScopeType.php          (JSON DBAL для list<Substrate>)
Coatings/Application/DTO/SurfaceTreatments/
  SurfaceTreatmentDTO.php
  SurfaceTreatmentDTOTransformer.php
Coatings/Application/UseCase/Command/
  CreateSurfaceTreatment/{Command,CommandHandler,CommandResult}.php
  UpdateSurfaceTreatment/{Command,CommandHandler,CommandResult}.php
  RemoveSurfaceTreatment/{Command,CommandHandler,CommandResult}.php
Coatings/Application/UseCase/Query/
  FindSurfaceTreatmentById/{Query,QueryHandler}.php
  ListSurfaceTreatments/{Query,QueryHandler}.php     (filter by substrate)
Coatings/Infrastructure/Controller/SurfaceTreatment/
  AddAction.php       — GET/POST /cabinet/coating/surface-treatment/add
  UpdateAction.php    — GET/POST /cabinet/coating/surface-treatment/{id}/update
  ListAction.php      — GET       /cabinet/coating/surface-treatment/list
  RemoveAction.php    — POST      /cabinet/coating/surface-treatment/{id}/remove
Coatings/Infrastructure/Api/SurfaceTreatment/
  CreateApiAction.php — POST /api/surface-treatments  (для inline модалки)
  ListApiAction.php   — GET  /api/surface-treatments?substrate=STEEL_CARBON  (для dropdown)
Coatings/Infrastructure/Mapper/
  SurfaceTreatmentMapper.php  (form/JSON ↔ DTO/Command)
Shared/Infrastructure/Templates/cabinet/coating/surface_treatment/
  list.html.twig
  form.html.twig
```

Роут пункта меню — «Подготовка поверхности», рядом с «Системы покрытий».

**Права:**
- List/View — все аутентифицированные.
- Add/Update — все аутентифицированные (включая inline API POST — чтобы работал inline-create в форме системы).
- Remove — только `ROLE_ADMIN`.

## Remove-логика (Restrict)

`RemoveSurfaceTreatmentCommandHandler`:
1. Загрузить treatment.
2. Спросить `CoatingSystemRepository::countUsingSurfaceTreatment(uuid): int`.
3. Если > 0 — `AppException`: «Нельзя удалить «{code|description}»: используется в N системах покрытий: {первые 5 titles}.»
4. Иначе — `$repo->remove($treatment)`.

## Форма CoatingSystem — переработка блока подготовки поверхности

Было:
```
[grade] [standard] [description]  (три text-input)
```

Станет:
```
[substrate dropdown]                   ← как и раньше
[surface treatment select (filtered by substrate)]  [+ Создать]
```

Кнопка «+ Создать» — открывает Bootstrap modal:
```
[description]
[code (опц.)]  [standardCode (опц.)]
[substrateScope multi-select]  ← преселект: текущий substrate системы
[Сохранить]  [Отмена]
```

Submit модалки → `POST /api/surface-treatments` → JSON `{id, description, code, standardCode, substrateScope, title}` → добавляется в основной select как выбранная.

Stimulus контроллер `surface_treatment_modal_controller.js`:
- targets: `modal`, `descriptionInput`, `codeInput`, `standardInput`, `scopeSelect`, `submitButton`, `errorBox`.
- action `submit`: fetch → success ? добавить option → close ? show error.

## Форма CoatingSystem — переработка блока слоёв

Было (Task 15):
```html
<input type="text" name="layers[N][coatingId]" placeholder="uuid">
```

Станет:
```html
<select name="layers[N][coatingId]" data-controller="coating-picker">
  {% for coating in coatings %}
    <option value="{{ coating.id }}">{{ coating.title }} ({{ coating.base.title }}, {{ coating.dftRange.min }}–{{ coating.dftRange.max }} мкм)</option>
  {% endfor %}
</select>
```

Опционально — обернуть в Tagify для typeahead (если в проекте уже используется — см. `coating_tags_controller.js`, `coating_form_controller.js`).

Контроллер `AddAction`/`UpdateAction` уже вызывает `GetPagedCoatingsQuery` (Task 15) — расширить, чтобы прокидывать полный список coatings в шаблон.

## API endpoints

**`GET /api/surface-treatments?substrate=STEEL_CARBON&q=Sa`**

Query params:
- `substrate`: `Substrate::value` — фильтр «substrate ∈ scope».
- `q`: search-string по code/description (LIKE, min 2 chars).

Response:
```json
{
  "items": [
    {"id": "...", "code": "Sa 2", "description": "...", "standardCode": "ГОСТ Р ИСО 8501-1", "substrateScope": ["STEEL_CARBON"]}
  ]
}
```

**`POST /api/surface-treatments`**

Body (JSON):
```json
{"description": "Обмыв водой", "code": null, "standardCode": null, "substrateScope": ["STEEL_CARBON"]}
```

Response 201:
```json
{"id": "...", "description": "Обмыв водой", "code": null, "standardCode": null, "substrateScope": ["STEEL_CARBON"], "title": "Обмыв водой"}
```

Response 422: `AppException` → `{result: "error", status: 422, message: "..."}` через `ResponseListener`.

## Миграция БД

Одна миграция `Version20260728000000`:
```sql
CREATE TABLE IF NOT EXISTS surface_treatment (
    id             UUID PRIMARY KEY,
    description    TEXT           NOT NULL,
    code           VARCHAR(30),
    standard_code  VARCHAR(100),
    substrate_scope JSONB         NOT NULL,
    created_at     TIMESTAMPTZ    NOT NULL,
    updated_at     TIMESTAMPTZ    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_surface_treatment_code_std
  ON surface_treatment (code, standard_code)
  WHERE code IS NOT NULL AND standard_code IS NOT NULL;

ALTER TABLE coating_system DROP COLUMN IF EXISTS surface_preparation_grade;
ALTER TABLE coating_system DROP COLUMN IF EXISTS surface_preparation_description;
ALTER TABLE coating_system DROP COLUMN IF EXISTS surface_preparation_standard;
ALTER TABLE coating_system ADD COLUMN IF NOT EXISTS
  surface_treatment_id UUID NOT NULL REFERENCES surface_treatment(id) ON DELETE RESTRICT;
```

В существующей БД `coating_system` 0 записей → `ADD COLUMN NOT NULL` без DEFAULT проходит. Если позже понадобится безопасность — сначала NULL, потом backfill, потом ALTER SET NOT NULL.

## Тесты

**Unit:**
- `SurfaceTreatmentTest` — инварианты (empty description, длины, empty scope).
- Обновить `CoatingSystemTest` — заменить фабрику `newTreatment()` вместо VO, тест на substrate ∉ scope → AppException.
- Удалить `SurfacePreparationTest`.

**Functional:**
- Repository round-trip (`SurfaceTreatmentRepositoryTest`).
- CommandHandlers (Create/Update/Remove с реальной БД + Restrict-check).
- Queries (Find/List с substrate filter).
- Controllers (Add/Update/List/Remove) + auth.
- API endpoints (Create/List) + фильтр по substrate.
- Модифицировать функциональные тесты Task 15 (CoatingSystem Add/Update) — прокидывать FK, не JSON.

## Backlog (не в этом деплое)

- Tagify autocomplete для treatment (пока обычный select).
- Кнопка «клонировать treatment» в CRUD.
- Отдельная страница view treatment с списком систем которые его используют.
- soft-delete для treatment (сейчас Restrict).
