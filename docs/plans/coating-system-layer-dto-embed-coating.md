# План: вложить CoatingDTO в CoatingSystemLayerDTO

Отдельная задача (выделена из фасета покрытий — см. `docs/plans/coating-system-coating-facet.md`).

## Контекст

`CoatingSystemLayerDTO` (`app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemLayerDTO.php`) дублирует поля покрытия — по сути развёрнутый `CoatingDTO`:

```php
public string $coatingId;
public string $coatingTitle;
public string $coatingBase;
public string $coatingBaseTitle;
public bool $isZincRich;
public string $manufacturerTitle;
public int $dftMin;
public int $dftMax;
```

Плюс собственные поля слоя: `id`, `position`, `dft`.

Цель — заменить дублирующие поля покрытия одним вложенным `public CoatingDTO $coating` (см. [[feedback_no_array_shape_dto]]: не держать в DTO развёрнутые копии, вкладывать типизированный DTO). Слой оставляет только своё: `id`, `position`, `dft`, `coating`.

## Почему отдельно

Заденет потребителей полей слоя — не только transformer, но и шаблоны/JS:
- `CoatingSystemDTOTransformer::layersFromSystem` — строит layer-DTO поштучно; переписать на `$layerDto->coating = $this->coatingDTOTransformer->fromEntity($coating)`.
- `_list_cards.html.twig` — `layer.coatingTitle`, `layer.dft` (payload preview-модалки).
- `_layers_edit.html.twig` — `layer.coatingTitle`, `layer.isZincRich`, `layer.manufacturerTitle`, `layer.coatingBaseTitle`, `layer.dftMin`, `layer.dftMax`, `layer.coatingId`, `layer.dft`.
- `coating_system_preview_controller.js` — payload `layers[].coatingTitle`, `dft`.
- `CoatingSystemDTOTransformerTest` — ассерты `layerDto->coatingTitle`, `coatingBase`, `coatingBaseTitle`, `isZincRich` → перевести на `layerDto->coating->title` и т.п.

Все обращения `layer.coatingTitle` → `layer.coating.title`, `layer.isZincRich` → `layer.coating.isZincRich`, `layer.dftMin` → `layer.coating.dftRange.min` и т.д. Нужен сквозной аудит грепом перед правкой.

## Изменения (набросок, детализировать перед реализацией)

1. `CoatingSystemLayerDTO`: убрать `coating*`/`isZincRich`/`manufacturerTitle`/`dftMin`/`dftMax`, добавить `public CoatingDTO $coating`. Оставить `id`, `position`, `dft`.
2. `CoatingSystemDTOTransformer`: инжектить `CoatingDTOTransformer`, в `layersFromSystem` собирать `coating` через него.
3. Шаблоны `_list_cards.html.twig`, `_layers_edit.html.twig`: `layer.coating.*`.
4. `coating_system_preview_controller.js` + места сборки payload: `layer.coating.title`, `layer.coating.dft`? (dft — свойство слоя, не покрытия! `layer.dft` остаётся; толщина покрытия по TDS — `layer.coating.dftRange`).
5. Тесты transformer — на `layer.coating->*`.
6. `cd app && yarn dev`.

## Важный нюанс

`dft` — свойство **слоя** (фактическая толщина в системе), остаётся на `CoatingSystemLayerDTO`. `dftMin`/`dftMax`/`tds_dft` — свойства **покрытия** (допустимый диапазон), уезжают в `layer.coating.dftRange`. Не перепутать при переносе.

## Тесты

- `CoatingSystemDTOTransformerTest` — round-trip: `layerDto->coating` содержит корректные title/base/isZincRich; `layerDto->dft`/`position` — свои.
- Прогнать рендер `ListActionTest` (шаблон `_list_cards` с новым shape).
