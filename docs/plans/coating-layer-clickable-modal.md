# Кликабельные слои систем → модалка покрытия

## Задача
На вьюхе систем покрытий (`cabinet/coating/coating_system`) слои системы — это покрытия.
Сделать каждый слой кликабельным: по клику всплывает **полная** модалка покрытия — та же,
что на вьюхе покрытий (шапка с производителем, «Основные характеристики», «Время высыхания»,
интервал перекрытия, химстойкость). Слои показаны в двух местах — **на карточке** и **в
модалке предпросмотра системы** — кликабельны должны быть оба (согласовано).

## Подход
Модалка покрытия тяжёлая и серверная: зависит от полного `CoatingDTO` +
`coating_time_matrix` + макроса `thermalRange` + `_chem_resistance_section`. Дублировать её
в JS запрещено правилом проекта («Не дублируй HTML между Twig и JS»). Поэтому:

1. **Выносим** модалку покрытия из `_coating_cards_batch.html.twig` в переиспользуемый партиал
   `_coating_preview.html.twig` (принимает `coating: CoatingDTO`, `canEdit: bool`). На вьюхе
   покрытий подключаем его же внутри цикла — визуально и по поведению ничего не меняется,
   разметка модалки живёт в одном месте.
2. **Новый эндпоинт-фрагмент** `GET /cabinet/coating/coating/{id}/preview` — рендерит только
   этот партиал для одного покрытия и отдаёт HTML-фрагмент (готовый `<div class="modal">`).
3. **Вьюха систем**: у слоя есть `coatingId` (уже в `CoatingSystemLayerDTO`). Делаем слой
   кликабельным (`data-coating-id`), маленький Stimulus-контроллер по клику фетчит фрагмент,
   кладёт в общий контейнер-модалку и показывает её. Один механизм для слоёв на карточке и
   в модалке системы.

Плюсы: ноль дублирования HTML, страница систем лёгкая (фетч по требованию), единый источник
разметки покрытия для обеих вьюх.

## Принятые решения (развилки согласованы)
- **Где кликабельно**: и на карточке, и внутри модалки предпросмотра системы — оба места.
- **Содержимое**: полная модалка покрытия (переиспользуем один партиал), не урезанная.
- **Вложенность**: клик по слою внутри модалки системы открывает модалку покрытия **поверх**
  (стек модалок Bootstrap 5); при закрытии возвращаемся к модалке системы.
- **Загрузка**: серверный партиал по фетчу на клик (не запекаем coating-данные в payload,
  не рендерим модалку в JS).

## Файлы

### Новые
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_coating_preview.html.twig`
  — вынесенная модалка покрытия (шапка → характеристики → время высыхания → интервал →
  химстойкость → футер). Макрос `thermalRange` переезжает сюда.
- `app/src/Coatings/Infrastructure/Controller/Coating/PreviewAction.php` — тонкий экшен,
  route `app_cabinet_coating_coating_preview`, path `/cabinet/coating/coating/{id}/preview`,
  GET. Диспатчит `GetCoatingQuery` → 404 если `coatingDTO` null → иначе рендерит
  `_coating_preview.html.twig` фрагментом (`coating`, `canEdit = is_granted('ROLE_ADMIN')`).
- Query — **переиспользуем существующий `GetCoatingQuery`** (`findOneById` +
  `CoatingDTOTransformer::fromEntity` → `?CoatingDTO`, ровно то, что нужно превью). Новый
  `GetCoatingPreview`-query НЕ плодим (стабы, что были в дереве, удалены как дубль).
- `app/assets/controllers/coating_preview_loader_controller.js` — Stimulus: `open(event)`
  → `stopPropagation`, читает `data-coating-id`, фетчит `/preview`, кладёт HTML в
  `container`-target, `new bootstrap.Modal(el).show()`. Лоадер/ошибку показывает минимально.

### Правим
- `_coating_cards_batch.html.twig` — вместо инлайн-модалки `{% include '_coating_preview.html.twig'
  with {coating, canEdit} %}` внутри цикла. Триггеры на карточке (`data-bs-target="#coatingPreview-{id}"`)
  остаются как есть — модалка теперь из партиала, id тот же.
- `cabinet/coating/coating_system/list.html.twig` — на общий wrapper вешаем
  `data-controller="... coating-preview-loader"` + контейнер-модалку
  (`data-coating-preview-loader-target="container"`).
- `cabinet/coating/coating_system/_list_cards.html.twig` — в списке слоёв карточки каждый
  слой → кликабельный (`role="button"`, `data-coating-id="{{ layer.coatingId }}"`,
  `data-action="click->coating-preview-loader#open"`). Клик по слою НЕ должен открывать
  модалку системы (её триггер — родительский div) → `stopPropagation` в контроллере.
  В `payloadLayers` добавляем `coatingId: layer.coatingId`.
- `assets/controllers/coating_system_preview_controller.js` — строки слоёв в `modalLayers`
  рендерим как кликабельные (`role="button"`, `dataset.coatingId`,
  `data-action="click->coating-preview-loader#open"`), чтобы работал тот же лоадер.

## Поведение
1. Клик по слою (карточка или модалка системы) → `coating-preview-loader#open`.
2. `stopPropagation` (иначе на карточке всплывёт триггер модалки системы).
3. Фетч `GET /cabinet/coating/coating/{coatingId}/preview` → HTML-фрагмент модалки.
4. Фрагмент кладётся в общий контейнер (перезаписывая прошлый), Bootstrap-модалка показывается.
5. Если клик был из модалки системы — модалка покрытия встаёт стеком поверх; закрытие
   возвращает к модалке системы.

## Тесты
- `tests/.../Controller/Coating/PreviewActionTest.php` (функциональный, реальная БД):
  существующий id → 200 + фрагмент содержит заголовок покрытия и блок «Основные характеристики»;
  несуществующий id → 404.
- Доменной логики не добавляем → юнит-тесты не нужны.
- JS/Twig — ручная проверка в браузере (клик слоя на карточке и в модалке системы).

## Проверка
- `vendor/bin/phpunit` затронутого контекста Coatings — зелёно.
- phpstan/cs-fixer по новым PHP-файлам — чисто.
- `yarn dev` (новый JS-контроллер + Twig).
- Браузер: клик по слою на карточке → полная модалка покрытия; клик по слою внутри модалки
  системы → модалка покрытия поверх, закрытие возвращает к системе; регресс вьюхи покрытий
  (модалка из партиала открывается как раньше).

## Риски / на что смотреть
- **Стек модалок**: клик из модалки системы открывает вторую модалку. Bootstrap 5 стек
  поддерживает; проверить backdrop/z-index и что закрытие верхней не роняет нижнюю.
- **Всплытие клика на карточке**: слой внутри триггера модалки системы — обязателен
  `stopPropagation`, иначе откроются обе.
- **Полнота `fromEntity`**: партиалу нужны `coating.chemResistancePage` и thermal-exposure;
  убедиться, что `CoatingDTOTransformer::fromEntity` их заполняет (лист-модалка их уже
  показывает через `fromEntityList`, значит заполняет).
