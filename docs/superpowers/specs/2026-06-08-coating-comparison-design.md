# Coating Comparison — Design

**Дата:** 2026-06-08
**Статус:** Дизайн
**Контекст:** Поверх существующего просмотра одного покрытия (`coating/index.html.twig` с превью-модалкой) добавляем side-by-side сравнение нескольких покрытий через корзину «Сравнить».

## Проблема

Сейчас пользователь, выбирая между несколькими покрытиями (например, грунты разных производителей под одну задачу), вынужден поочерёдно открывать превью-модалку каждого и держать значения в голове. Side-by-side сравнения нет.

## Цель

1. Дать пользователю собирать корзину из 2–4 покрытий с любой страницы списка/поиска и видеть их таблицу характеристик рядом, с подсветкой различающихся строк.
2. Сделать страницу сравнения визуально максимально близкой к превью-модалке (та же структура секций, тот же порядок строк, те же лейблы), чтобы у пользователя формировалась единая mental model просмотра покрытия.
3. Заложить базу под будущий «подбор аналогов» (отдельная фича, out of scope), при котором аналог будет просто предзаполнением корзины сравнения.

## Out of scope

- Подбор аналогов / поиск похожих — следующая итерация.
- Сохранение / именование сетов сравнения для повторного использования.
- Кросс-устройственный персистент корзины (после выбора `Symfony session` корзина живёт в рамках сессии).
- Скрытие одинаковых строк (compact-режим). Пользователь видит таблицу один-в-один, как превью.
- Экспорт сравнения в PDF/CSV.
- Сравнение `CoatingSystem` (многослойных систем) — отдельный аггрегат, отдельная фича.
- Live-обновление корзины через JS/XHR. Используем обычный POST + redirect.

## Принципы

- **Без своего аггрегата.** Корзина — это `list<string>` id-шников в сессии. Никаких миграций, никакой денормализации.
- **Доступно всем авторизованным**, не только админам. Сравнение — research-инструмент.
- **POST для всех мутаций + CSRF**, как в существующих формах.
- **Подсветку считает сервис, не шаблон.** Шаблон только переключает CSS-класс по флагу. Тесты пишутся на сервисе, не на Twig.
- **Та же визуальная семантика, что в превью покрытия.** Те же секции, те же лейблы, те же единицы (час/сутки/мкм/°C/об. %/кг/л/л).

## Архитектура

Меняются точки в трёх слоях:

```
Application
  └─ Service/
      ├─ ComparisonBasket          — типизированная обёртка над сессией
      └─ ComparisonDiffService     — флаги «эта строка различается»

Infrastructure
  └─ Controller/Comparison/
      ├─ ShowAction                — GET страница сравнения
      ├─ AddAction                 — POST добавить id, redirect back
      ├─ RemoveAction              — POST убрать id, redirect back
      └─ ClearAction               — POST очистить корзину, redirect back

Shared
  └─ Templates/
      ├─ admin/coating/coating/compare.html.twig
      ├─ components/comparison_bar.html.twig
      └─ admin/coating/coating/index.html.twig   (toggle-кнопка в карточке)
      └─ cabinet/index.html.twig                 (include comparison_bar)
```

### ComparisonBasket

`App\Coatings\Application\Service\ComparisonBasket`. Сервис, инжектится `RequestStack`. Хранит состояние под ключом `coating.comparison.basket` как `list<string>`.

Публичный контракт:

```php
final class ComparisonBasket
{
    public const MAX_ITEMS = 4;
    private const SESSION_KEY = 'coating.comparison.basket';

    public function __construct(private readonly RequestStack $requestStack) {}

    /** @return list<string> */
    public function ids(): array;

    public function count(): int;

    public function isFull(): bool;

    public function contains(string $id): bool;

    /** @throws BasketFullException если уже MAX_ITEMS и $id не в корзине */
    public function add(string $id): void;

    public function remove(string $id): void;

    public function clear(): void;
}
```

Внутри: read/write `$this->requestStack->getSession()->set(...)`. Дедуплицируем через `in_array` перед `add`. Игнорируем повторный add того же id (idempotent). `BasketFullException` — кастомное исключение в `App\Coatings\Application\Service\Exception\`, в контроллере ловится и превращается во флеш.

### ComparisonDiffService

`App\Coatings\Application\Service\ComparisonDiffService`. Чистый сервис без зависимостей.

Публичный контракт:

```php
final class ComparisonDiffService
{
    /**
     * @param list<CoatingDTO> $coatings
     * @return array<string, bool>   ключ = имя строки таблицы, значение = есть ли различия
     */
    public function computeDiffMarkers(array $coatings): array;
}
```

Возвращаемые ключи (имена строк таблицы):

| Ключ                       | Источник в DTO                        |
|----------------------------|---------------------------------------|
| `base`                     | `baseEnum().value`                    |
| `volumeSolid`              | `volumeSolid`                         |
| `massDensity`              | `massDensity`                         |
| `dftRange`                 | `dftRange['min']`, `['max']`, `['tds_dft']` (целиком) |
| `applicationMinTemp`       | `applicationMinTemp`                  |
| `pack`                     | `pack`                                |
| `thinner`                  | `thinner` (`null` нормализуем в `''`) |
| `manufacturer`             | `manufacturer.id`                     |
| `dryToTouch`               | `dryToTouch[0]['time_in_minutes']`    |
| `fullCure`                 | `fullCure[0]['time_in_minutes']`      |
| `minRecoatingInterval`     | `minRecoatingInterval`                |
| `maxRecoatingInterval`     | `maxRecoatingInterval` (`null` ≠ число) |
| `tags`                     | сет id тегов, сравниваем как `sort+implode` |

Правило: строка считается различающейся (`true`), если множество значений из всех колонок имеет более одного уникального элемента. Описание (`description`) не входит в карту — длинный текст не подсвечивается, в шаблоне просто показывается как есть.

### Контроллеры

`App\Coatings\Infrastructure\Controller\Comparison\` — новый каталог в существующей структуре контроллеров.

| Action          | Route                                                          | Метод |
|-----------------|----------------------------------------------------------------|-------|
| `ShowAction`    | `/cabinet/coating/comparison`                                  | GET   |
| `AddAction`     | `/cabinet/coating/comparison/add/{id}`                         | POST  |
| `RemoveAction`  | `/cabinet/coating/comparison/remove/{id}`                      | POST  |
| `ClearAction`   | `/cabinet/coating/comparison/clear`                            | POST  |

Имена роутов: `app_cabinet_coating_comparison_show|add|remove|clear`.

- `AddAction` ловит `BasketFullException` → `addFlash('comparison_full', '...')` → redirect back.
- Все mutating actions: redirect на `$request->headers->get('referer') ?? path('app_cabinet_coating_coating_list')`.
- CSRF-токен в форме каждой кнопки toggle / X / Clear. Проверка через `$this->isCsrfTokenValid('comparison', $request->getPayload()->get('_csrf_token'))`.
- `ShowAction` достаёт ids → грузит DTO через `GetCoatingQuery` каждый (N≤4) → отсеивает несуществующие (тихо, без падения) → отдаёт массив + `ComparisonDiffService` → рендерит `compare.html.twig`.
- Если в корзине 0 — `ShowAction` redirect на список с флешем «Корзина сравнения пуста».

### Шаблоны

**`components/comparison_bar.html.twig`** — sticky-бар. Включаем в `cabinet/index.html.twig` после `{% block content %}`:

```twig
{% set basketIds = comparison_basket_ids() %}
{% if basketIds|length > 0 %}
    <nav class="comparison-bar fixed-bottom bg-body-tertiary border-top shadow-sm py-2">
        <div class="container-lg d-flex align-items-center gap-3">
            <span class="fw-semibold">Сравнение:</span>
            <div class="d-flex flex-wrap gap-2 flex-grow-1">
                {% for item in comparison_basket_items() %}
                    <span class="badge text-bg-light border d-flex align-items-center gap-1">
                        {{ item.title }}
                        <form method="post" action="{{ path('app_cabinet_coating_comparison_remove', {id: item.id}) }}" class="d-inline">
                            <input type="hidden" name="_csrf_token" value="{{ csrf_token('comparison') }}">
                            <button type="submit" class="btn btn-link btn-sm p-0 lh-1 text-decoration-none" aria-label="Убрать">&times;</button>
                        </form>
                    </span>
                {% endfor %}
            </div>
            <a href="{{ path('app_cabinet_coating_comparison_show') }}"
               class="btn btn-primary btn-sm {% if basketIds|length < 2 %}disabled{% endif %}">
                Сравнить ({{ basketIds|length }})
            </a>
            <form method="post" action="{{ path('app_cabinet_coating_comparison_clear') }}" class="d-inline">
                <input type="hidden" name="_csrf_token" value="{{ csrf_token('comparison') }}">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Очистить</button>
            </form>
        </div>
    </nav>
{% endif %}
```

`comparison_basket_ids()` и `comparison_basket_items()` — две Twig-функции (Twig extension). Items резолвит id → массив `{id, title}` через сервис (1 SQL).

**Toggle-кнопка в `index.html.twig`** — рядом с edit/delete блоком, видна всем (не за `canEdit`). POST на add/remove в зависимости от `basket.contains(id)`. Иконки: `bi-bar-chart` (filled когда уже в корзине), tooltip меняется.

**`compare.html.twig`** — новая страница:
- Шапка: «Сравнение покрытий», справа — «К списку» и «Очистить корзину».
- Если `coatings|length == 1`: алерт «Добавьте ещё одно покрытие для сравнения», под ним одна колонка.
- Иначе: таблица из секций, идентичных превью-модалке. Каждая секция — `<table>` с двумя+ колонками значений (по числу coatings).
- Подсветка: `<tr class="{% if diff.<key> %}table-warning{% endif %}">`.
- Над каждой колонкой — название покрытия, производитель, X для удаления (POST на remove).

### Twig extension

`App\Coatings\Infrastructure\Twig\ComparisonExtension` — две функции:
- `comparison_basket_ids(): list<string>` → `ComparisonBasket::ids()`.
- `comparison_basket_items(): list<array{id, title}>` → для каждого id делает `GetCoatingQuery`, отсеивает несуществующие, возвращает массив `{id, title}`. N≤4, поэтому 4 SQL — приемлемо. Внутри расширения — лоу-кост мемоизация в свойстве (один render не должен делать запросы дважды).

## Подсветка различий — правила

Для каждого ключа из таблицы выше:

```
collect values from each CoatingDTO column
if count(unique values) > 1 → diff[key] = true
else → diff[key] = false
```

Особые случаи:
- `dftRange`: «значение» — кортеж `(min, max, tds_dft)`. Различие по любому → diff.
- `tags`: «значение» — отсортированный список id, склеенный через `,`. Порядок добавления не важен.
- `thinner`: `null` нормализуется в `''`.
- `maxRecoatingInterval`: `null` ≠ любое число; null строго отличается от `0.0`.
- `description` — в diff-map отсутствует. Шаблон рендерит описание без подсветки.

`table-warning` (бутстраповский желтоватый фон) — на всю `<tr>`. На уровне `<td>` подсветку не дробим: пользователь видит, какая характеристика расходится, и сам сравнивает значения.

## Лимит и UX-крайние случаи

- Добавление 5-го → `BasketFullException` → flash `comparison_full` со словами «Максимум 4 покрытия. Убери одно, чтобы добавить новое.» → redirect back.
- Удаление того, чего нет — игнорируется тихо.
- Add того же id повторно — idempotent, без флеша.
- `ShowAction` при пустой корзине → redirect back на список с флешем «Корзина пуста».
- В `ShowAction` если хотя бы один id из сессии указывает на несуществующее покрытие — фильтруем тихо и продолжаем. Если после фильтрации осталось 0 — поведение как при пустой корзине.

## Тесты

### Unit

- `ComparisonBasketTest`
  - `addEmpty_addsId`
  - `addSame_idempotent`
  - `addBeyondLimit_throwsBasketFullException`
  - `remove_existing_dropsId`
  - `remove_unknown_noop`
  - `clear_emptiesBasket`
  - `contains_reflectsState`
  - `count_isCorrect`
  - `isFull_atLimit`
  - Подменяем `RequestStack` на `Stack` с in-memory сессией (`MockArraySessionStorage`).

- `ComparisonDiffServiceTest`
  - `singleCoating_allDiffsFalse` — корзина из 1, всё одинаково по определению.
  - `twoIdenticalCoatings_allDiffsFalse` — DTO с одинаковыми полями.
  - `twoDifferingOnVolumeSolid_onlyVolumeSolidTrue`
  - `differentDftRangeMin_dftRangeTrue`
  - `differentTags_tagsTrue`
  - `differentManufacturer_manufacturerTrue`
  - `nullThinnerVsEmpty_treatedAsSame`
  - `nullMaxRecoatingVsZero_treatedAsDifferent`
  - `fourCoatingsMixed_correctMarkersForEachKey`

### Functional / интеграционные

Не пишем. UI и controller-логика покрывается ручной проверкой.

## Roadmap → следующая итерация

«Подбор аналогов» (out of scope сейчас):
- Кнопка «Подобрать аналоги» на странице сравнения / в превью модалке.
- Алгоритм: фильтр по совпадающему `base` (через `canReceive`/`canBeAppliedOnTopOf`) + ranking по close-метрике (DFT range overlap, volumeSolid distance, recoat interval overlap).
- Результат алгоритма предзаполняет корзину сравнения → пользователь сразу видит side-by-side. Никакой новой UI-поверхности не нужно.

Поэтому корзина и страница сравнения сейчас спроектированы так, чтобы аналоги в них «вливались» бесшовно.
