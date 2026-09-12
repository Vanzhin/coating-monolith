# Соотношение компонентов в форме редактирования покрытия

Отдельная задача (Coatings admin-форма). Даёт UI для заполнения `mixing_ratio` — прежде было
отложено, теперь нужно (в т.ч. чтобы по-человечески засеять данные для Деплоя 3 калькулятора).
Ветка `feat/coating-mixing-ratio-form`, стеком поверх `feat/tools-coating-search` (чтобы домен +
калькулятор + форма были в одном дереве и проверялись сквозняком; зависит жёстко только от Д1).

Домен/ORM/DBAL/миграция готовы — не трогаем. Добавляем только application + форму по рецепту
CLAUDE.md, образец — nullable вложенный VO `ThermalExposureLimits`.

## UX секции (согласовать)

Секция «Соотношение смешивания компонентов» — как прочие `.fsec`-блоки формы, стиль таблицы
`table-rows` (как серии сушки). Столбцы:

| Компонент | Части по объёму | Части по массе | ✕ |
|-----------|-----------------|-----------------|---|
| Основа    | [ 3 ]           | [ 100 ]         | 🗑 |
| Компонент 2 | [ 1 ]         | [ 23 ]          | 🗑 |

- «Добавить компонент» (кнопка), старт с 2 строк. Столбец слева — «Основа», «Компонент N».
- Подсказка: «Пусто — однокомпонентное покрытие. Заполните хотя бы одну базу; если обе — число
  компонентов должно совпадать.»
- Инпуты `type="number" min="0" step="0.01"` (как в форме). Пустые ячейки → база не задана.
- Якорь в TOC: `{id:'sec-mix', label:'Смешивание'}`.

Правила (≥2 компонента, >0, ≤2 знака, ≥1 база, равное число при обеих базах) enforce ДОМЕН
(`MixingRatio`/`PartsRatio`/`PositiveNumber` → AppException → баннер формы). В Assert — только
структура (numeric).

## Файлы и правки (по рецепту)

1. **Новый** `Application/DTO/Coatings/MixingRatioDTO.php` — `?list<float> $volume`, `?list<float> $mass`
   (вложенный DTO, без array-шейпов; образец `ThermalExposureLimitsDTO`).
2. `Application/DTO/Coatings/CoatingDTO.php` — `public ?MixingRatioDTO $mixingRatio = null;`.
3. `Infrastructure/Mapper/CoatingMapper.php`:
   - `buildCoatingDtoFromInputData` → `buildMixingRatioFromInput($input['mixingRatio'] ?? [])`:
     `cleanParts()` отбрасывает пустые ячейки (иначе `PositiveNumber((float)'')`=0 → AppException),
     `array_values` (разреженные индексы после удаления → list); обе базы пусты → `null`
     (иначе однокомпонентные упадут на «задайте хотя бы одну базу»).
   - `buildInputDataFromDto` → `decomposeMixingRatioForForm(...)` → `{volume:[...], mass:[...]}`
     (тот же shape, что POST — чтобы форма восстановилась после ошибки).
   - `getValidationCollectionCoating` → `Assert\Optional(Assert\Collection{volume/mass:
     Optional(All(Type numeric)), allowExtraFields})`. Бизнес-правил тут НЕТ.
4. `Application/DTO/Coatings/CoatingDTOTransformer.php::fromEntity` → `mixingRatioDto(...)`:
   `entity->getMixingRatio()` → DTO (`getByVolume()?->getParts()`, `getByMass()?->getParts()`).
5. `Domain/Service/CoatingMaker.php::make` — параметр `?MixingRatio $mixingRatio = null`, вызвать
   `setMixingRatio(...)` СТРОГО до `repository->add()` (сеттеры после flush не персистятся).
6. `Application/UseCase/Command/CreateCoating/CreateCoatingCommandHandler.php` — helper
   `buildMixingRatio(?MixingRatioDTO): ?MixingRatio` = `MixingRatio::fromArray([...])` (или null),
   прокинуть в `make(...)`.
7. `Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php` — тот же helper +
   безусловно `setMixingRatio(...)` (пустая секция = стало однокомпонентным; как exposure).
8. `Templates/admin/coating/coating/form.html.twig` — секция `#sec-mix` (таблица `table-rows`) +
   якорь в `anchors`.
9. `assets/controllers/coating_form_controller.js` — изолированные `addMixingRow`/
   `removeMixingRow`/`_reindexMixingRows` (НЕ переиспользуем `addRow` — он завязан на
   `[temperature_at]`/имена серий). Переиндексация имён `mixingRatio[volume|mass][i]` после удаления.

Не трогаем: домен, ORM, DBAL, миграцию, контроллеры Add/Update (уже ловят AppException → баннер).

## Тесты
- Mapper round-trip (unit): `buildInputDataFromDto(build(...))` для volume-only / both / пусто;
  пустые ячейки отбрасываются; обе пусты → null.
- Функц. Update-handler: сохранить покрытие с соотношением (обе базы) → перечитать, соотношение
  на месте; очистить секцию → `getMixingRatio()` null. Домен-инварианты (рассинхрон баз, <2,
  >2 знаков) уже покрыты юнит-тестами VO — не дублируем в форме.
- `./run check` + `yarn dev` + браузер (ввод/удаление строк, сохранение, ошибка при рассинхроне).

## После — верификация Деплоя 3
Через эту форму задать соотношение паре покрытий → проверить калькулятор `/tools/mix`: поиск →
подстановка (locked), Объём/Масса, «отказ» у покрытий без соотношения.
