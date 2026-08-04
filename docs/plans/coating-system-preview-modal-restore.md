# План: восстановить модалку предпросмотра и унифицировать формат слоёв

Финальное место после апрува — `docs/plans/coating-system-preview-modal-restore.md` (по CLAUDE.md `docs/plans/`).

## Контекст

На странице `/cabinet/coating/coating-system/list` карточка системы должна открывать модалку предпросмотра `#coatingSystemModal`. Модалка и её Stimulus-контроллер уже написаны (`list.html.twig:830-902`, `coating_system_preview_controller.js`), но `_list_cards.html.twig` не пробрасывает на карточке ни `data-action`, ни `data-payload` — поэтому клик по карточке модалку не открывает. Пользователь описал это как «сейчас не работает» — так и есть.

Параллельно нужно унифицировать вид слоёв: и в карточке списка, и в модалке показывать слои в формате «Название 100 мкм», по одному на строку (перенос строки), порядок primer → finish (снизу вверх по системе, сверху вниз по экрану). База (`coatingBaseTitle`) и Zn(R)-бэйдж — убрать, только `coatingTitle` и `dft`.

Порядок слоёв уже правильный: `CoatingSystem::getLayers()` (`app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php:148-150`) применяет `Criteria::orderBy(['position' => Ascending])`, а `CoatingSystemDTOTransformer::layersFromSystem` (`.../CoatingSystemDTOTransformer.php:47-66`) сохраняет этот порядок. В шаблоне выводим `system.layers` как есть.

Цвет слоя, редактирование слоёв в форме, расширение Coating «доступные цвета/колеруется», сущность Color с seed и типа-эд — это самостоятельные фичи, идут отдельными планами (см. раздел «Следующие деплои»).

## Изменения

### 1. Карточка списка — открытие модалки

Файл: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_list_cards.html.twig`

Внутри цикла `{% for system in items %}`:

- Собрать payload из DTO в переменной `{% set payload = { id, title, substrateTitle, surfaceTreatmentTitle (surfaceTreatmentDescription как fallback), totalDft, description, layers: [{coatingTitle, dft}, ...], compliance } %}`. Формат `layers` — только `coatingTitle` и `dft`, лишние поля (`position`, `coatingBase*`, `isZincRich`, `coatingId`, `id`) не пробрасываем.
- Кликабельная зона — внутренний блок `<div class="flex-grow-1 overflow-hidden">` (строки 25-79 существующей карточки). На него навесить:
  - `role="button"`
  - `data-action="click->coating-system-preview#open"`
  - `data-payload="{{ payload|json_encode|e('html_attr') }}"`
- Заголовок системы (`<h6>` со ссылкой на `app_cabinet_coating_system_update`, стр. 26-31): заменить `<a>` на просто `<span class="text-body">{{ system.title }}</span>`. Редактирование — через кнопку внутри модалки (уже есть, стр. 891-895 в `list.html.twig`) и через существующий блок `edit_delete` справа.
- Блок `{% include '/components/edit_delete.html.twig' %}` (стр. 80-89) остаётся вне кликабельной зоны — не будет ложных срабатываний триггера модалки.

### 2. Слои в самой карточке

Тот же файл `_list_cards.html.twig`. После блока с бэйджами (после `{% endif %}` compliance-блока, перед tags-блоком) добавить секцию состава:

```twig
{% if system.layers|length > 0 %}
    <div class="small text-body-secondary mb-1">
        {% for layer in system.layers %}
            <div>{{ layer.coatingTitle }} {{ layer.dft }} мкм</div>
        {% endfor %}
    </div>
{% endif %}
```

Каждый слой в собственном `<div>` — естественный перенос строки. Стили не сочиняем (следуем правилу «не изобретай стили»): текст мелкий, приглушённый — консистентно с блоками «Мин.Т нанесения» и «Время сборки» рядом (стр. 49-58).

Бэйдж «`<i class="bi bi-layers"></i> {{ system.layers|length }}`» из ряда бэйджей (стр. 34-36) убрать — количество слоёв теперь видно из самого списка. Общая ТСП (`{{ system.totalDft }} мкм`, стр. 37-39) остаётся.

### 3. Модалка предпросмотра — таблица → список строк

Файл: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig`

Стр. 869-882 (`<div class="table-responsive"><table>...</table></div>`) заменить на простой контейнер `<div data-coating-system-preview-target="modalLayers"></div>`. Заголовок «Слои системы (`<span data-coating-system-preview-target="modalLayersCount"></span>`)» — оставить.

### 4. JS-контроллер модалки — заполнение списка

Файл: `app/assets/controllers/coating_system_preview_controller.js`

В `_fill` (стр. 63-78) заменить генерацию `<tr>...</tr>` на:

```js
const container = this.modalLayersTarget;
container.innerHTML = '';
(data.layers ?? []).forEach(layer => {
    const row = document.createElement('div');
    row.textContent = `${layer.coatingTitle} ${layer.dft} мкм`;
    container.appendChild(row);
});
```

Убрать использование `layer.position`, `layer.isZincRich`, `layer.coatingBaseTitle` — они больше не пробрасываются в payload.

Всё остальное (compliance, description, edit-link, delete-link) не трогаем.

## Критические файлы

- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_list_cards.html.twig` — карточка списка (payload, кликабельная зона, блок слоёв)
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig` — модалка (table → div)
- `app/assets/controllers/coating_system_preview_controller.js` — заполнение списка слоёв

Никаких изменений в PHP (DTO, транзформер, контроллер) — данных достаточно.

## Существующие переиспользуемые точки

- Сортировка слоёв: `CoatingSystem::getLayers()` (`app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php:148-150`).
- DTO уже готов: `CoatingSystemLayerDTO` (`app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemLayerDTO.php`).
- Кнопки edit/delete: `components/edit_delete.html.twig` — без изменений.
- Delete-модалка: `components/delete_modal.html.twig` — без изменений (роут `RemoveAction` уже принимает GET после предыдущего фикса).
- Twig-фильтр `|e('html_attr')` — встроенный, безопасное экранирование JSON в HTML-атрибут.

## Verification

1. Пересобрать ассеты: `cd app && yarn dev`.
2. Открыть `/cabinet/coating/coating-system/list` в браузере.
3. Убедиться, что в каждой карточке под бэйджами появился список слоёв «Название 100 мкм» построчно, порядок primer сверху → finish снизу.
4. Клик по контентной части карточки — открывается модалка предпросмотра; данные слоёв внутри в том же формате и порядке.
5. Клик по названию системы (уже не ссылка) — открывает модалку (не переход на update).
6. Клик по кнопкам «Редактировать»/«Удалить» справа — их поведение сохранено (переход/подтверждение), модалка предпросмотра не открывается.
7. Внутри модалки кнопка «Редактировать» ведёт на страницу update; «Закрыть» закрывает модалку. Кнопки удаления в модалке нет (её и раньше не было).
8. Если compliance пустой — блок соответствий скрыт; если description пустой — блок описания скрыт (поведение уже было).
9. Прогнать функциональные тесты: `cd app && vendor/bin/phpunit tests/Unit/Coatings` (не должно ничего сломаться — правки только UI).

## Следующие деплои (отдельные планы, не в текущий)

- `docs/plans/color-context.md` — сущность Color (название + опциональный RAL), миграция, seed (базовые: серый/белый/бежевый/чёрный/красный + подборка RAL), репозиторий, suggest-endpoint.
- `docs/plans/coating-colors.md` — у Coating: список доступных цветов (M2M) + флаг «колеруется» (`isTintable`). Форма Coating: типа-эд по цветам + чекбокс.
- `docs/plans/coating-system-layer-color.md` — у `CoatingSystemLayer`: nullable `color`. Вход в форме редактирования слоя. Отображение в карточке и модалке.
- `docs/plans/coating-system-layers-ajax-edit.md` — AJAX-редактирование слоёв в форме системы (endpoint'ы поверх `AppendLayer/InsertLayerAt/MoveLayer/RemoveLayerAt/UpdateLayerDft`, кнопки вверх/вниз, показ ошибок как в форме Coating).
