# Полные наименования подготовки поверхности в акте (enum short + documentText)

## Задача

В документе-акте поля блока `surface_prep` печатаются коротким кодом («Sa 2½», «1», «Средний профиль G»),
а нужно полное предложение со стандартом:
- `Подготовка поверхности: Абразивоструйная очистка до степени Sa 2½ по ISO 8501-1.`
- `Шероховатость поверхности: Средняя G – между 2 и 3 сегментами, исключая сегмент 3, компаратора G по ISO 8503-2.`
- `Обеспыливание поверхности: Соответствует 2 классу по количеству и размеру частиц пыли согласно ISO 8502-3.`

Идея: у классификации короткое имя (форма/UI) и полное (документ).

## Провенанс (почему так)

Одна и та же величина (степень по ISO 8501-1) размазана тремя моделями:
- **Reports** `SurfacePrepBlock` — инлайновые строковые `options` руками, без enum.
- **Proposals** `CoatingSystemSurfaceTreatment` — enum, но `value` = средний формат «Sa 2,5 (по ГОСТ…)», только Sa/St, домен Proposals.
- **Coatings** `SurfaceTreatment` — сущность-справочник (заявленная подготовка системы, не классификатор).

Это ГОСТ/ISO-классификации — общая предметная величина. Канонический дом — **`Shared/Domain`**. НЕ тянем enum
Proposals в Reports (был бы кросс-контекст leak) и НЕ плодим 4-ю копию. Proposals/Coatings на новые enum'ы
пока НЕ мигрируем (у Proposals свой value+данные) — отдельный будущий флажок.

## Хранение — НЕ меняется

`Report::content` — JSON со строками; в `surface_prep` лежит `{"prepDegree":"Sa 2½","rustGrade":"A","dedusting":"2","roughness":"..."}`.
Enum накладывается СВЕРХУ при чтении: его backing-`value` = ровно те строки, что уже лежат → существующие
отчёты читаются `Enum::tryFrom($строка)` без миграции. Roughness — исключение (был свободный текст), см. ниже.

## Enum'ы (Shared)

Строковые enum'ы в `Shared/Domain/Aggregate/Enum/` (там же `ThicknessType`), `use EnumToArray`,
`implements DocumentTextEnum`. `value` = короткий код (форма/UI), `documentText()` = полное предложение.

Новый контракт-интерфейс `App\Shared\Domain\Aggregate\Enum\DocumentTextEnum`:
```php
interface DocumentTextEnum { public function documentText(): string; }
```

### PreparationDegree (ISO 8501-1) — value = текущие опции
- `Sa1 = 'Sa 1'`, `Sa2 = 'Sa 2'`, `Sa2Half = 'Sa 2½'`, `Sa3 = 'Sa 3'`, `St2 = 'St 2'`, `St3 = 'St 3'`.

### RustGrade (ISO 8501-1) — value = 'A'|'B'|'C'|'D'

### DedustingClass (ISO 8502-3) — value = '1'|'2'|'3'

### SurfaceRoughness (ISO 8503-2) — НОВЫЕ value (поле было текстом). 5 грейдов × компаратор (G=grit, S=shot) = 10 кейсов
Грейды: тоньше тонкого / тонкий / средний / грубый / грубее грубого (крайние — редкие).
- G: `FinerFineG='Тоньше тонкого G'`, `FineG='Тонкий G'`, `MediumG='Средний G'`, `CoarseG='Грубый G'`, `CoarserCoarseG='Грубее грубого G'`
- S: `FinerFineS='Тоньше тонкого S'`, `FineS='Тонкий S'`, `MediumS='Средний S'`, `CoarseS='Грубый S'`, `CoarserCoarseS='Грубее грубого S'`

**Формулировки `documentText()` — ЧЕРНОВИК по ISO/ГОСТ, вычитать:**

| Enum / value | documentText() (черновик) |
|---|---|
| PreparationDegree Sa 1 | Лёгкая абразивоструйная очистка до степени Sa 1 по ISO 8501-1. |
| Sa 2 | Абразивоструйная очистка до степени Sa 2 по ISO 8501-1. |
| Sa 2½ | Абразивоструйная очистка до степени Sa 2½ по ISO 8501-1. |
| Sa 3 | Абразивоструйная очистка до визуально чистой стали, степень Sa 3 по ISO 8501-1. |
| St 2 | Ручная и механизированная очистка до степени St 2 по ISO 8501-1. |
| St 3 | Тщательная ручная и механизированная очистка до степени St 3 по ISO 8501-1. |
| RustGrade A | Степень A по ГОСТ Р ИСО 8501-1-2014 |
| B | Степень B по ГОСТ Р ИСО 8501-1-2014 |
| C | Степень C по ГОСТ Р ИСО 8501-1-2014 |
| D | Степень D по ГОСТ Р ИСО 8501-1-2014 |
| DedustingClass 1 | класс 1 по количеству и размеру частиц пыли согласно ISO 8502-3. |
| 2 | класс 2 по количеству и размеру частиц пыли согласно ISO 8502-3. |
| 3 | класс 3 по количеству и размеру частиц пыли согласно ISO 8502-3. |
| SurfaceRoughness Тоньше тонкого G | Тоньше тонкого G – мельче сегмента 1 компаратора G по ISO 8503-2. |
| Тонкий G | Тонкий G – между 1 и 2 сегментами, исключая сегмент 2, компаратора G по ISO 8503-2. |
| Средний G | Средний G – между 2 и 3 сегментами, исключая сегмент 3, компаратора G по ISO 8503-2. |
| Грубый G | Грубый G – между 3 и 4 сегментами, исключая сегмент 4, компаратора G по ISO 8503-2. |
| Грубее грубого G | Грубее грубого G – крупнее сегмента 4 компаратора G по ISO 8503-2. |
| Тоньше тонкого S | Тоньше тонкого S – мельче сегмента 1 компаратора S по ISO 8503-2. |
| Тонкий S | Тонкий S – между 1 и 2 сегментами, исключая сегмент 2, компаратора S по ISO 8503-2. |
| Средний S | Средний S – между 2 и 3 сегментами, исключая сегмент 3, компаратора S по ISO 8503-2. |
| Грубый S | Грубый S – между 3 и 4 сегментами, исключая сегмент 4, компаратора S по ISO 8503-2. |
| Грубее грубого S | Грубее грубого S – крупнее сегмента 4 компаратора S по ISO 8503-2. |

## Привязка Field → enum

`App\Reports\Domain\Block\Field`:
- Новый параметр `?string $enum = null` (class-string enum'а, реализующего `DocumentTextEnum`).
- `options` перестаёт быть promoted: если `enum !== null` и `options` пуст → `options = $enum::values()`
  (из `EnumToArray`). Иначе — как передали. Так форма (`$field->options`) и валидатор (`in_array`) не меняются.

`SurfacePrepBlock`:
- `prepDegree` → `enum: PreparationDegree::class` (опции убрать — возьмутся из enum).
- `rustGrade` → `enum: RustGrade::class`.
- `dedusting` → `enum: DedustingClass::class`.
- `roughness` → `FieldType::Text` → `FieldType::Enum`, `enum: SurfaceRoughness::class`.
- `blasting_media` — БЕЗ изменений (свободный текст, вплетается в предложение шаблоном).

## Проектор (документ)

`ReportRenderDataProjector::formatScalar` для `FieldType::Enum` c `$field->enum`:
```php
$enumClass = $field->enum;
if (null !== $enumClass && is_a($enumClass, \BackedEnum::class, true)) {
    $case = $enumClass::tryFrom((string) $value);
    if ($case instanceof DocumentTextEnum) {
        return $case->documentText();
    }
}
return (string) $value; // fallback: неизвестное значение (старый roughness-текст) — как есть
```
UI/форма НЕ трогается (там короткий `value`/`options`). Развязка short↔full — только тут.

Шаблон-композиция (сегментом, поле media — текстом):
```
Подготовка поверхности: {{surface_prep_prepDegree}}{{?blasting_media}} В качестве абразива использована: {{surface_prep_blasting_media}}{{/?blasting_media}}
```

## Совместимость / загвоздки

- prepDegree/rustGrade/dedusting: value = существующие строки → старые отчёты в документе получают полное
  предложение без миграции.
- **roughness (Text→Enum):**
  - Документ: старый свободный текст не совпадёт с кейсом → fallback печатает его как есть (без полного
    предложения). Новые — из дропдауна → полное. (Пользователь принял.)
  - Форма/валидация: после смены на Enum валидатор требует значение из `options`. Старый свободный текст
    при СЛЕДУЮЩЕМ сохранении отчёта станет невалидным → пользователь перевыберет из списка. Задокументировать.

## Файлы

- Создать: `Shared/Domain/Aggregate/Enum/DocumentTextEnum.php`, `PreparationDegree.php`, `RustGrade.php`,
  `DedustingClass.php`, `SurfaceRoughness.php`.
- Правка: `Reports/Domain/Block/Field.php` (параметр `enum` + options-из-enum), `SurfacePrepBlock.php`
  (привязка), `ReportRenderDataProjector.php` (`formatScalar` резолв enum→documentText).
- Тесты: unit на каждый enum (`tryFrom(value)`, `documentText()` не пуст, все кейсы покрыты),
  `Field` (options берутся из enum), проектор (enum-поле → documentText в документе + fallback на
  неизвестном значении). Существующий `ReportRenderDataProjectorTest` / валидатор-тесты — прогнать на регресс.
- Доки: `docs/plans/report-template-placeholders.md` — surface_prep теперь полный текст (пометить, что
  значения — enum, документ печатает полное).

## Порядок работ
1. Интерфейс `DocumentTextEnum` + 4 enum'а (Shared) + их unit-тесты (формулировки — черновиком, потом вычитка).
2. `Field`: параметр `enum`, options-из-enum + тест.
3. `SurfacePrepBlock`: привязка (roughness Text→Enum).
4. `formatScalar`: резолв enum→documentText + fallback; тест проектора.
5. `./run check` зелёный (валидатор Enum на `options` из enum — регресс).
6. Обновить контракт плейсхолдеров.
7. Вычитка формулировок пользователем → правки в `documentText()`.

## Отложено
- Консолидация Proposals `CoatingSystemSurfaceTreatment` и Coatings `SurfaceTreatment` на общие Shared-enum'ы —
  отдельная задача (у Proposals свой value-формат + данные, нужна миграция).
- Компаратор S (shot) для шероховатости — добавить при необходимости.
