# План: инлайн-редактирование слоёв системы покрытий

Финальное место после апрува — `docs/plans/coating-system-layers-ajax-edit.md`.

## Контекст

На странице `/cabinet/coating/coating-system/{id}/update` (форма редактирования системы) блок «Слои системы» — заглушка «Слои системы редактируются отдельно после сохранения метаданных» (`app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig` стр. 172-179). Пользователи не могут менять состав системы через UI, хотя в домене готовы все нужные команды: `AppendLayer`, `RemoveLayerAt`, `MoveLayer`, `UpdateLayerDft` (в `app/src/Coatings/Application/UseCase/Command/`).

Задача — заменить заглушку на живой блок с AJAX-действиями:
- добавить слой (typeahead coating + ТСП + «Добавить»);
- изменить ТСП существующего слоя (input + «Сохранить»);
- переместить слой вверх/вниз;
- удалить слой.

Смена покрытия у существующего слоя не поддерживается по решению — «удалить + добавить заново» (для этого не нужна новая доменная команда). Вставка в середину (`InsertLayerAt`) в UI не выводится — достаточно append + move (сама доменная команда остаётся в кодбазе).

Ошибки от домена (`AppException` — «толщина вне диапазона», «покрытия несовместимы») пробиваются через существующие Symfony-listener'ы (`AppExceptionHtmlListener` для HTML, `ExceptionListener` для JSON) и рендерятся `<div class="alert alert-danger">` в верхней части блока — тот же паттерн, что в форме Coating (`admin/coating/coating/form.html.twig` стр. 20-29).

CSRF — не добавляем: остальные cabinet-роуты `coating-system/*` тоже без CSRF-токенов (консистентно с уже-существующим модулем).

## Изменения

### 1. Четыре per-action контроллера (endpoints)

Разместить в `app/src/Coatings/Infrastructure/Controller/CoatingSystem/Layer/`:

- `AppendAction.php` — POST `/cabinet/coating/coating-system/{id}/layer/append`, body `coatingId`, `dft`.
- `RemoveAction.php` — POST `/cabinet/coating/coating-system/{id}/layer/{position}/remove` (requirements: `position=\d+`).
- `MoveAction.php` — POST `/cabinet/coating/coating-system/{id}/layer/{position}/move`, body `to` (целевая позиция).
- `UpdateDftAction.php` — POST `/cabinet/coating/coating-system/{id}/layer/{position}/dft`, body `dft`.

Каждый Action:
1. Валидирует shape входа (Symfony Validator: uuid для coatingId, int для dft/position/to).
2. Выполняет соответствующую команду через `CommandBusInterface` (`AppendLayerCommand` etc.).
3. По окончании — тянет свежий `CoatingSystemDTO` через `FindCoatingSystemByIdQuery` и рендерит партиал `_layers_edit.html.twig`, возвращает `text/html`.
4. При исключении `AppException` — не ловится в контроллере, пробивается через `AppExceptionHtmlListener` для запросов с `Accept: text/html`; для fetch-запросов JS явно шлёт `Accept: text/html`, но парсит JSON, если ответ не-2xx. Альтернатива — контроллер ловит `\Exception`, рендерит партиал с переменной `$error` и статусом 422 (проще + консистентно с формой Coating). Выбираем второй вариант — единый response-shape (всегда partial), без развилки в JS.

Копировать разметку роутов и структуру Action'ов из `app/src/Coatings/Infrastructure/Controller/CoatingSystem/UpdateAction.php` (там уже есть паттерн: FindByIdQuery → CommandBus → render).

### 2. Партиал `_layers_edit.html.twig`

Файл: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_layers_edit.html.twig`.

Вход: `system` (`CoatingSystemDTO`), `error` (nullable string), `coatingTitlesById` (map для preselected typeahead в форме добавления).

Разметка:
- Обёртка `<div data-controller="coating-system-layers-edit" data-coating-system-layers-edit-system-id-value="{{ system.id }}">`.
- Если `error` — `<div class="alert alert-danger">{{ error }}</div>` над списком.
- Список слоёв — таблица (по паттерну модалки preview `list.html.twig:869-882`, но с полями редактирования):
  - № позиции.
  - Название покрытия (read-only span, не select).
  - `<input type="number">` для dft + кнопка `<button data-action="click->coating-system-layers-edit#saveDft">Сохранить</button>`.
  - Кнопки `data-action="click->coating-system-layers-edit#moveUp/moveDown"` (`bi-arrow-up`/`bi-arrow-down`; disabled для первой/последней позиции).
  - Кнопка `data-action="click->coating-system-layers-edit#remove"` (`bi-trash`).
- Форма добавления в конце блока — копия «Слои системы» из create-режима `form.html.twig:93-171`, но без `name="layers[N][…]"` (обычные input-ы, отправляем AJAX). Одна строка с typeahead coating (`async-typeahead` — уже готов) и dft-input + кнопка «Добавить слой» (`data-action="click->coating-system-layers-edit#append"`).

### 3. Stimulus-контроллер `coating_system_layers_edit_controller.js`

Файл: `app/assets/controllers/coating_system_layers_edit_controller.js`.

Values:
- `systemId: String`
- URL'ы для 4 endpoint'ов через `data-…-value` (по паттерну `coating_system_preview_controller.js` values).

Actions:
- `append(event)` — читает coatingId + dft из формы добавления, POST на append endpoint (`FormData` или JSON).
- `remove(event)` — POST на remove endpoint с position из `data-position` кнопки.
- `moveUp(event)` / `moveDown(event)` — POST на move endpoint с `from=position` и `to=position∓1`.
- `saveDft(event)` — читает dft из input, POST на dft endpoint.

Все действия:
1. `fetch(url, { method: 'POST', body, headers: { 'Accept': 'text/html' } })`.
2. Ответ (200 или 422) — всегда HTML партиал. Заменяем `outerHTML` корневого div контроллера содержимым ответа (Stimulus сам переинициализирует новый контроллер при `MutationObserver`).

Не изобретаем стили — вся кнопочная разметка копируется из create-режима form (`form.html.twig:98-101`, 133-138, 163-167).

### 4. Форма update: заменить заглушку

Файл: `form.html.twig` (существующий).

Строки 172-179 (блок `{% else %}` заглушки) заменить на:
```twig
{% include 'cabinet/coating/coating_system/_layers_edit.html.twig' with {
    system: layersDto,
    coatingTitlesById: coatingTitlesById|default({}),
    error: layersError|default(null),
} %}
```

### 5. UpdateAction: пробросить DTO для партиала

Файл: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/UpdateAction.php`.

В существующие `render()`-вызовы (GET-ветка + catch-ветка) добавить переменные `layersDto: $dto` (уже получаемый через `FindCoatingSystemByIdQuery`) и `coatingTitlesById` (map, собранный из `$dto->layers`). `layersError` — по умолчанию null.

## Критические файлы

- Новые:
  - `app/src/Coatings/Infrastructure/Controller/CoatingSystem/Layer/AppendAction.php`
  - `app/src/Coatings/Infrastructure/Controller/CoatingSystem/Layer/RemoveAction.php`
  - `app/src/Coatings/Infrastructure/Controller/CoatingSystem/Layer/MoveAction.php`
  - `app/src/Coatings/Infrastructure/Controller/CoatingSystem/Layer/UpdateDftAction.php`
  - `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_layers_edit.html.twig`
  - `app/assets/controllers/coating_system_layers_edit_controller.js`
  - `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/Layer/*ActionTest.php` (4 файла)
- Изменяемые:
  - `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig` — замена заглушки.
  - `app/src/Coatings/Infrastructure/Controller/CoatingSystem/UpdateAction.php` — проброс DTO в шаблон.

## Существующие переиспользуемые точки

- Команды: `AppendLayerCommand`, `RemoveLayerAtCommand`, `MoveLayerCommand`, `UpdateLayerDftCommand` — сигнатуры без изменений.
- Query: `FindCoatingSystemByIdQuery` (`app/src/Coatings/Application/UseCase/Query/FindCoatingSystemById/`).
- CommandBusInterface / QueryBusInterface (`App\Shared\Application\{Command,Query}\*`).
- Async-typeahead: `data-controller="async-typeahead"` + endpoint `app_cabinet_coating_coating_suggest` (уже используется в create-режиме формы).
- Разметка (не сочиняем стили) — копируем из create-режима формы (`form.html.twig:93-171`).
- Doctrine-агрегат: `CoatingSystem::appendLayer/removeLayerAt/insertLayerAt/moveLayer/updateLayerDft` уже проверяют инварианты.

## Verification

1. Пересобрать ассеты: `cd app && yarn dev`.
2. Открыть `/cabinet/coating/coating-system/{id}/update` в браузере.
3. Проверить каждое действие:
   - Добавить слой: typeahead выбирает покрытие, ввод ТСП, кнопка «Добавить» — новый слой появляется в конце.
   - Изменить ТСП: изменить input, «Сохранить» — dft обновился.
   - Переместить вверх/вниз: порядок меняется, стрелка на границах disabled.
   - Удалить: строка исчезает, позиции пересчитываются.
4. Проверить ошибки:
   - dft = 0 или отрицательный → `alert alert-danger` с сообщением от домена.
   - Несовместимые покрытия (нарушение recoating-tree) → alert с русским сообщением.
   - Позиция вне диапазона (не должно случаться из UI, но проверить curl'ом) → 422 + alert.
5. Прогнать тесты: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/Layer`.
6. Кеш `coating_system_search` / `coating_system_compliance` обновляется автоматически через `RefreshCacheOnCoatingSystemMutatedHandler` (`app/src/Coatings/Application/Event/`) — команды слоёв кидают тот же мутационный эвент, что и `UpdateCoatingSystemMetadata`. Отдельно вызывать не нужно; проверить это фактически после первого append/remove в UI.

## Следующие задачи (не в этот план)

- «Поиск и фильтр работают плохо» — уточнить сценарии и завести отдельный план `docs/plans/coating-system-search-fixes.md`.
- Задача цвета (Color-контекст, `coating.availableColors`, `layer.color`) — семейство планов, из ранее обсуждённого.
