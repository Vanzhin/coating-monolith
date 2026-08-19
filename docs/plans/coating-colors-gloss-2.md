# Цвет в слое системы покрытий (Деплой 2)

> **План 2** из двух. Предпосылка: `coating-colors-gloss-1.md` уже в проде — у покрытий есть возможные цвета,
> флаг `isTintable` и справочник `Color`. Без этого деплоя плана 1 работать нельзя.

## Задача

Каждый слой системы покрытий (`CoatingSystemLayer`) получает **выбранный цвет**. Выбор обязателен.
Правило источника:
- покрытие слоя **не колеруемое** → цвет выбирается только из возможных цветов этого покрытия;
- покрытие слоя **колеруемое** (`isTintable`) → любой цвет из справочника (или создать новый через ту же модалку).

Цвет слоя показывается везде, где показан слой: на карточке системы и в модалке предпросмотра — свотч + название
рядом с названием покрытия и толщиной.

## Подход

Слой уже держит живую ссылку `many-to-one` на `Coating` (не снапшот) и валидирует dft против диапазона покрытия
(`assertDftInCoatingRange`). Цвет добавляется тем же приёмом: слой получает `many-to-one` на `Color`, а инвариант
членства проверяется в самом слое через данные его покрытия (`layer.getCoating()`), не в маппере/хендлере.

На фронте выбор цвета встраивается в существующий редактор слоёв (`_layers_edit.html.twig` +
`coating_system_layers_edit_controller.js`). Когда для слоя выбрано покрытие, подтягиваем его цвета и флаг
колеруемости отдельным фетчем и рендерим селектор цвета:
- не колеруемое → `<select>` из возможных цветов покрытия (опции со свотчем);
- колеруемое → тот же `coating-colors` typeahead + модалка создания (переиспользуем из Плана 1), любой цвет.

## Принятые решения (развилки согласованы)

- **Цвет слоя обязателен** для каждого слоя (на запись).
- **Источник**: не колеруемое → только из списка покрытия; колеруемое → любой цвет справочника.
- **Существующие слои**: колонка `color_id` в БД **nullable** (легаси-слои без цвета переживают миграцию),
  но обязательность форсится на записи (маппер `colorId` NotBlank). Старые системы показывают «цвет не выбран», пока
  их не пересохранят — осознанный технический долг, не ломает данные.
- **Выкат — сразу строго** (без мягкой фазы). ВАЖНАЯ ПРЕДПОСЫЛКА: цвет слоя не-колеруемого покрытия обязан быть из
  `possibleColors` этого покрытия. Легаси-покрытия без цветов делают свои системы **несохраняемыми** (слой не раскрасить
  → NotBlank не удовлетворить). Поэтому покрытия должны быть обогащены цветами ПЕРВЫМИ (авто-бэкфилл невозможен — цвета
  вводит человек). Годится, если систем со слоем на легаси-покрытии мало/нет.
- **Отвязка цвета от покрытия при использовании в системе** — оставляем как есть (не каскадим, не запрещаем). Цвет
  create-only, `Color` не удаляется, FK цел, система показывает старый цвет. Инвариант `assertColorAllowed` сработает
  при следующем сохранении системы → админ перевыберет. Самолечащаяся рассинхронизация.
- **Контрол цвета в строке слоя** — единый одиночный typeahead (Tagify `mode:'select'`) со свотчами: не-колеруемое →
  подсказки только из цветов покрытия; колеруемое → глобальный справочник `/color/suggest` + «+ Создать» (общая модалка).

## Актуализация под текущий код (сверено на ветке feat/coating-system-layer-color)

Точные текущие сигнатуры (в них вплетаем цвет):
- `CoatingSystemLayer::__construct(Uuid $id, CoatingSystem $system, Coating $coating, int $position, int $dft)` — добавить `Color $color` (перед position, как в задумке).
- `CoatingSystem::appendLayer(Coating $coating, int $dft)`, `insertLayerAt(int $position, Coating $coating, int $dft)`,
  `replaceLayers(array $items)` где item = `{coating: Coating, dft: int}` — во все добавить `Color`/`color`.
- `ReplaceLayersCommand->items` = `list<{coatingId, dft}>`, `CreateCoatingSystemCommand->initialLayers` то же — добавить `colorId`.
- Оба хендлера резолвят `Coating` через `CoatingRepositoryInterface::findOneById`; рядом резолвим `Color` через
  `ColorRepositoryInterface::findOneById` (уже есть).
- ORM слоя `coating_system_layer` (table), `many-to-one coating fetch=EAGER` — добавить `many-to-one color` + колонка `color_id`.

**Две ловушки (важно):**
1. Обязательный `colorId` в слоях сломает существующие functional-тесты систем (создают слои без цвета) — по аналогии
   с Планом 1. Обновить: `CreateCoatingSystemTest`, `CoatingSystemRepositoryTest`-фикстуры, `UpdateAction`-тесты систем,
   `ReplaceLayers`-тесты — дать каждому слою валидный `colorId` (создать `Color` в фикстуре и привязать к покрытию слоя,
   т.к. для не-колеруемого покрытия цвет обязан быть из его `possibleColors`).
2. JS `coating_system_layers_edit_controller.js::_renumber()` ищет скрытый инпут по `input[type="hidden"]` — со вторым
   hidden (`colorId`) сломается (возьмёт первый). Переписать выбор инпутов на `data-role` (`[data-role="coatingId"]`,
   `[data-role="colorId"]`, `[data-role="dft"]`), а не по типу.

## Домен и инварианты

- **`CoatingSystemLayer`** — новое поле **`?Color $color`** (nullable ради легаси-слоёв). Конструктор получает
  `?Color $color = null` **последним параметром** (`(Uuid, CoatingSystem, Coating, int $position, int $dft, ?Color
  $color = null)`) — чтобы не ломать существующие вызовы. Геттер `getColor(): ?Color`, метод `changeColor(?Color)`.
  Инвариант `assertColorAllowed(?Color $color, Coating $coating)`:
  - `if (null === $color) return;` — легаси/не задан: пропускаем (обязательность форсится на записи, не в домене).
  - `if ($coating->isTintable()) return;` — колеруемое: любой цвет ок.
  - иначе `$color` должен присутствовать в `$coating->getPossibleColors()` по id, иначе `AppException('Цвет «…» не
    входит в возможные цвета покрытия «…».')`.
  Вызывается из конструктора и `changeColor`.
- **`CoatingSystem`** — методы получают цвет **опционально, последним параметром** (не ломая 30+ существующих вызовов):
  `appendLayer(Coating $coating, int $dft, ?Color $color = null)`, `insertLayerAt(int, Coating, int, ?Color = null)`,
  `replaceLayers(array $items)` где item = `{coating, dft, color?}` (ключ `color` опционален). Инварианты агрегата
  (`postMutate`) не меняются — цвет проверяется на уровне слоя.
- **Обязательность** цвета — на **записи**: `CoatingSystemMapper::getValidationCollection` требует `layers[*][colorId]`
  `NotBlank + Uuid`. Домен допускает null (легаси), но всякий новый submit несёт colorId.

## Файлы

### Правим — бэкенд

- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemLayer.php` — поле `color`, `getColor`, `changeColor`,
  `assertColorAllowed`.
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — прокинуть `Color` в
  `appendLayer/insertLayerAt/replaceLayers` (+ точечный `updateLayerColor` при необходимости).
- `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystemLayer.orm.xml` — `many-to-one
  field="color"` → `Color`, join-column `color_id` (nullable, fetch EAGER).
- `app/src/Coatings/Application/UseCase/Command/ReplaceLayers/{ReplaceLayersCommand,Handler}.php` — item получает
  `colorId`; хендлер резолвит `Color` через `ColorRepositoryInterface::findOneById` и передаёт в `replaceLayers`.
- `app/src/Coatings/Application/UseCase/Command/CreateCoatingSystem/*` — то же для создания системы.
- `app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php`:
  - `layersFromInput` — нормализовать `layers[i][colorId]`.
  - `getValidationCollection` — `layers[*][colorId]`: `NotBlank + Uuid` (членство/колеруемость — домен, не форма).
  - `buildInputDataFromDto` — вернуть `colorId` (+ для рендера свотча: `colorName`, `colorHex`) в строки слоёв.
- `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemLayerDTO.php` — `+ colorId, colorName, colorRal,
  colorHex`.
- `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php::layersFromSystem` — заполнить
  цветовые поля из `layer->getColor()`.
- Новый эндпоинт `app/src/Coatings/Infrastructure/Controller/Coating/CoatingColorsAction.php` —
  `GET /cabinet/coating/coating/{id}/colors` → JSON `{isTintable: bool, colors: [{id,name,ral,hex}]}` для заполнения
  селектора цвета при выборе покрытия в слое.

### Правим — фронтенд

- `cabinet/coating/coating_system/_layers_edit.html.twig` — в строке слоя добавить селектор цвета (для
  существующих слоёв — предвыбранный `colorId` + свотч); в блоке добавления слоя — селектор цвета рядом с dft,
  плюс переиспользуемая модалка создания цвета (для колеруемого покрытия). `<template>` строки — hidden `colors`/select.
- `app/assets/controllers/coating_system_layers_edit_controller.js` — при выборе покрытия фетчить `/colors`,
  по `isTintable` рендерить либо `<select>` из списка, либо `coating-colors` typeahead; проброс выбранного цвета в
  hidden `layers[i][colorId]`; блокировать добавление слоя без цвета (UX; серверная проверка — в домене).
- `cabinet/coating/coating_system/_list_cards.html.twig` — в чипе слоя показать свотч + название цвета рядом с
  названием покрытия и `dft`.
- `app/assets/controllers/coating_system_preview_controller.js` — строки слоёв в модалке предпросмотра рендерить со
  свотчем цвета (данные уже в `CoatingSystemLayerDTO`/payloadLayers).

### Миграция

- Идемпотентная `Version*.php`: `ALTER TABLE coatings_coating_system_layer ADD COLUMN IF NOT EXISTS color_id
  varchar(36) NULL` + FK на `coatings_coating_color(id)` (nullable, без каскада). Бэкфилла нет — легаси-слои остаются
  без цвета до пересохранения.

## Поведение (редактор системы)

1. Выбрал покрытие для слоя → фетч `/coating/{id}/colors`.
2. Не колеруемое → селектор цвета = его возможные цвета (свотчи); колеруемое → typeahead любого цвета + «Создать цвет».
3. Цвет обязателен: без него слой не добавить (UX); при сабмите пустой/чужой цвет → 422 из домена, рендер в форме.
4. Сохранение: `layers[i][colorId]` → `ReplaceLayersCommand`/`CreateCoatingSystemCommand` → резолв `Color` →
   `replaceLayers` → инвариант слоя.
5. Карточка и модалка предпросмотра системы показывают свотч+название цвета у каждого слоя.

## Тесты

- Unit `tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemLayerTest.php`: цвет из списка покрытия → ok;
  чужой цвет у не-колеруемого → `AppException`; любой цвет у колеруемого → ok.
- Functional (реальная БД): create/replace слоёв с `colorId` (валидный / чужой → 422); `CoatingColorsAction`
  (JSON с `isTintable` и списком).
- Mapper round-trip слоёв с цветом.
- JS/Twig — ручная проверка: селектор переключается по колеруемости; свотчи на карточке и в модалке.

## Проверка

- `./run check` — зелёно.
- `cd app && yarn dev`.
- Браузер: выбор цвета в слое (оба режима), сохранение, отображение свотча слоя на карточке и в модалке предпросмотра;
  регресс: существующие системы без цвета открываются и показывают «цвет не выбран».

## Риски / на что смотреть

- **Обязательность vs легаси**: домен требует цвет, БД-колонка nullable ради старых слоёв. Следить, чтобы новые
  сохранения всегда писали цвет, а старые не падали при чтении/отображении.
- **Смена покрытия у существующего слоя** может обнулить валидность выбранного цвета (не входит в новый список) —
  на фронте при смене покрытия сбрасывать выбранный цвет и заново тянуть `/colors`.
- **Переиспользование модалки/typeahead цветов** из Плана 1 — не дублировать разметку/JS (правило проекта).
