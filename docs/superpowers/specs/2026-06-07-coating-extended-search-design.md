# Extended Coating Search — Design

**Дата:** 2026-06-07
**Статус:** Дизайн
**Контекст:** Поверх существующего FTS-поиска по `coatings_coating.title/description` добавляем расширенный поиск с фасетами. В первой итерации — только фасет «производитель».

## Проблема

`/cabinet/coating/search` сейчас умеет искать только по полнотекстовой строке. Кейс пользователя «литум грунт» не работает: слово «литум» — это название производителя (`coatings_manufacturer.title`), а FTS индексирует только поля самого `Coating`. Чтобы добавить такие сценарии, нужны фасеты, которые применяются вместе с текстовым поиском.

## Цель

1. Дать пользователю фильтр по производителю поверх текстового поиска.
2. Заложить архитектурный паттерн, к которому в последующих итерациях прирастут другие фасеты (теги, диапазон DFT, диапазон сухого остатка, цвет).
3. Не ломать текущий простой кейс: «нажал поиск без фильтров — работает как сейчас».

## Out of scope

- Денормализация `manufacturer.title` в `search_vector` (явно отвергнуто — пользователь предпочёл фасеты).
- Все фасеты кроме производителя (теги, range DFT, range volumeSolid, цвет) — следующие итерации.
- Live-фильтрация (автосабмит при клике чекбокса) — JS не используем.
- Lazy-load списка производителей через AJAX — производителей мало, грузим сразу.
- Пагинация результатов в `search.html.twig` — её и сейчас нет, отдельная задача.

## Принципы

- **Один SQL-запрос** для FTS + фасетов. Postgres сам выбирает план; кода в PHP меньше.
- **Фасеты применяются и к fuzzy-фоллбэку**: иначе при опечатке вернутся «похожие по названию» покрытия от других производителей — нелогично.
- **Архитектурное разделение через приватные методы**: `applyFtsClause`, `applyFacets`, `applyManufacturerFacet` — каждый отвечает за свою часть `QueryBuilder`. Новый фасет добавляется как новый `applyXxxFacet` рядом.
- **Невалидные ID — тихо в null**: если в URL мусор вместо UUID, фильтр игнорирует фасет. AppException бросаем только за «осмысленный, но запрещённый» ввод (короткий поиск — да, мусорный UUID — нет).
- **Пустая страница без критериев**: первый заход без `?search=` и без `?manufacturerIds[]` — показывает форму без выполнения запроса.

## Архитектура

Меняются 5 точек по слоям:

```
Domain
  └─ CoatingsFilter         — добавляется array $manufacturerIds + нормализация
Application
  └─ (без изменений)        — GetPagedCoatingsQuery работает с тем же фильтром
Infrastructure
  ├─ Search/CoatingFinder    — методы принимают CoatingsFilter целиком,
  │                           applyFacets как точка роста
  ├─ Repository/CoatingRepository — findByFilter становится проще
  ├─ Controller/SearchAction — читает ?manufacturerIds[], грузит список
  │                           производителей для UI
  └─ Templates/search.html.twig — collapse-блок с чекбоксами производителей
```

## Components

### CoatingsFilter

```php
readonly class CoatingsFilter
{
    private const MIN_SEARCH_LENGTH = 3;
    private const MAX_SEARCH_LENGTH = 50;

    public ?string $search;

    /** @var list<string> Список UUID производителей. Пустой массив — фасет не применяется. */
    public array $manufacturerIds;

    public ?Pager $pager;

    public function __construct(
        ?string $search = null,
        array $manufacturerIds = [],
        ?Pager $pager = null,
    ) {
        $this->search = $this->normalizeSearch($search);
        $this->manufacturerIds = $this->normalizeManufacturerIds($manufacturerIds);
        $this->pager = $pager;
    }

    private function normalizeManufacturerIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_string($id) && Uuid::isValid($id)) {
                $clean[] = $id;
            }
        }
        return array_values(array_unique($clean));
    }
}
```

Поведение:
- Пустая строка/null/мусор в `$ids` → отбрасываются.
- Дубликаты схлопываются.
- Невалидный UUID — тихо игнорируется (фасет не применяется).
- Контракт нормализации search — без изменений (3-50 символов, иначе throw AppException).

### CoatingFinder

`fullText` и `fuzzyTitle` принимают `CoatingsFilter` целиком (раньше — `string $query`). FTS-условие и фасеты строятся в одном `QueryBuilder`.

```php
final class CoatingFinder
{
    public function fullText(CoatingsFilter $filter): PaginationResult
    {
        $qb = $this->coatingQueryBuilder();
        $this->applyFtsClause($qb, $filter);
        $this->applyFacets($qb, $filter);
        $this->applyPaging($qb, $filter->pager);

        return $this->paginate($qb, false);
    }

    public function fuzzyTitle(CoatingsFilter $filter): PaginationResult
    {
        if ($filter->search === null) {
            return new PaginationResult([], 0);
        }

        $qb = $this->coatingQueryBuilder();
        $similarity = 'GREATEST(WORD_SIMILARITY(:search, cc.title), WORD_SIMILARITY(:search, cc.description))';
        $qb->andWhere($similarity . ' > :threshold')
            ->addSelect($similarity . ' AS HIDDEN sim')
            ->orderBy('sim', 'DESC')
            ->setMaxResults(self::FUZZY_LIMIT)
            ->setParameter('search', $filter->search)
            ->setParameter('threshold', self::FUZZY_SIMILARITY_THRESHOLD);

        $this->applyFacets($qb, $filter);

        return $this->paginate($qb, false);
    }

    private function applyFtsClause(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->search === null) {
            $qb->orderBy('cc.title', 'ASC');
            return;
        }

        $tsquery = $this->buildPrefixTsQuery($filter->search);
        if ($tsquery === '') {
            $qb->andWhere('1 = 0');
            return;
        }

        $qb->innerJoin(CoatingSearch::class, 's', 'WITH', 's.coatingId = cc.id')
            ->andWhere('TS_MATCH(s.searchVector, TO_TSQUERY(:lang, :tsquery)) = TRUE')
            ->addSelect('TS_RANK_CD(s.searchVector, TO_TSQUERY(:lang, :tsquery)) AS HIDDEN fts_rank')
            ->orderBy('fts_rank', 'DESC')
            ->setParameter('lang', self::FTS_LANG)
            ->setParameter('tsquery', $tsquery);
    }

    private function applyFacets(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        $this->applyManufacturerFacet($qb, $filter);
        // позже: applyTagsFacet, applyDftRangeFacet, applyVolumeSolidFacet
    }

    private function applyManufacturerFacet(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->manufacturerIds === []) {
            return;
        }
        $qb->andWhere('cc.manufacturer IN (:manufacturerIds)')
            ->setParameter('manufacturerIds', $filter->manufacturerIds);
    }
}
```

### CoatingRepository

```php
public function findByFilter(CoatingsFilter $filter): PaginationResult
{
    $result = $this->finder->fullText($filter);
    if ($result->total === 0 && $filter->search !== null) {
        return $this->finder->fuzzyTitle($filter);
    }
    return $result;
}
```

`normalizeSearch` из репозитория удаляется — нормализация теперь в `CoatingsFilter`.

### SearchAction

```php
public function __invoke(Request $request): Response
{
    $search = $request->query->get('search');
    $manufacturerIds = $request->query->all('manufacturerIds');
    $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
    $limit = $request->query->get('limit') ? (int) $request->query->get('limit') : null;
    $pager = Pager::fromPage($page, $limit);

    $manufacturersResult = $this->queryBus->execute(
        new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
    );

    $hasAnyCriterion = ($search !== null && trim($search) !== '') || $manufacturerIds !== [];

    $result = null;
    $error = null;
    if ($hasAnyCriterion) {
        try {
            $filter = new CoatingsFilter(
                search: $search,
                manufacturerIds: $manufacturerIds,
                pager: $pager,
            );
            $result = $this->queryBus->execute(new GetPagedCoatingsQuery($filter));
        } catch (AppException $e) {
            $error = $e->getMessage();
        }
    }

    return $this->render('admin/coating/coating/search.html.twig', [
        'search' => $search ?? '',
        'selectedManufacturerIds' => $manufacturerIds,
        'manufacturers' => $manufacturersResult->manufacturers,
        'result' => $result,
        'error' => $error,
    ]);
}
```

### UI: search.html.twig

```twig
<form method="get" action="{{ path('app_cabinet_coating_coating_search') }}" class="my-3">
    <div class="input-group input-group-lg mb-3">
        <input type="search" name="search" value="{{ search }}"
               class="form-control"
               placeholder="По названию и описанию (3-50 символов)"
               minlength="3" maxlength="50" autocomplete="off">
        <button type="submit" class="btn btn-primary">Найти</button>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <button class="btn btn-outline-secondary btn-sm" type="button"
                data-bs-toggle="collapse"
                data-bs-target="#advancedFilter"
                aria-expanded="{{ selectedManufacturerIds ? 'true' : 'false' }}"
                aria-controls="advancedFilter">
            <i class="bi bi-funnel"></i> Расширенный поиск
            {% if selectedManufacturerIds|length > 0 %}
                <span class="badge bg-primary ms-1">{{ selectedManufacturerIds|length }}</span>
            {% endif %}
        </button>

        {% if search or selectedManufacturerIds %}
            <a href="{{ path('app_cabinet_coating_coating_search') }}" class="text-muted small">
                Сбросить
            </a>
        {% endif %}
    </div>

    <div class="collapse {% if selectedManufacturerIds %} show {% endif %}" id="advancedFilter">
        <div class="card card-body">
            <label class="form-label">Производитель</label>
            <div class="row">
                {% for m in manufacturers %}
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="checkbox"
                                   class="form-check-input"
                                   name="manufacturerIds[]"
                                   value="{{ m.id }}"
                                   id="manufacturer-{{ m.id }}"
                                   {% if m.id in selectedManufacturerIds %} checked {% endif %}>
                            <label class="form-check-label" for="manufacturer-{{ m.id }}">
                                {{ m.title }}
                            </label>
                        </div>
                    </div>
                {% endfor %}
            </div>
        </div>
    </div>
</form>
```

## Решения по умолчанию

| Решение | Выбор | Обоснование |
|---|---|---|
| Один SQL vs два этапа | Один SQL с `AND` | Postgres сам выбирает план; меньше кода |
| Архитектурное разделение | Через приватные методы `applyXxxFacet` | Один QueryBuilder, отдельная единица ответственности на фасет |
| Single vs multi production | Multi (`array $manufacturerIds`) | Пользователь может хотеть фильтровать по нескольким производителям |
| Mass loading vs lazy load производителей | Загружаем сразу при заходе на /search | Список из десятков значений; AJAX-сложность не оправдана |
| UI multi-select | Чекбоксы в две колонки | Тот же стиль уже в create.html.twig (теги). Без JS |
| Раскрытие расширенного блока | Bootstrap collapse (`data-bs-toggle`) | Ноль JS. Раскрывается автоматически если фасет уже применён |
| Live-фильтр | Submit по кнопке | Простой UX, предсказуемо; JS не нужен |
| Невалидный UUID в фильтре | Тихо игнорировать | Технический мусор, не ошибка пользователя |
| Невалидная длина search | AppException → alert | Доменное правило, требует обратной связи |
| Fuzzy-fallback с фасетами | Применяет те же фасеты | Иначе вернутся «похожие» от других производителей |
| Сортировка без search | По title ASC | Естественный дефолт для каталога |
| Pager-сохранение GET-параметров | Не в скоупе | Пагинация не реализована — отдельная задача |

## План работ (укрупнённо)

1. **`CoatingsFilter`** — добавить `$manufacturerIds`, `normalizeManufacturerIds`. Обновить все callsites (`SearchAction`, тесты).
2. **`CoatingFinder`** — переписать `fullText`/`fuzzyTitle` на приём `CoatingsFilter`. Вынести `applyFtsClause`, `applyFacets`, `applyManufacturerFacet`.
3. **`CoatingRepository::findByFilter`** — упростить (нормализация ушла в Filter). Передавать filter в Finder целиком.
4. **`SearchAction`** — добавить `?manufacturerIds[]`, грузить список производителей через существующий `GetPagedManufacturersQuery`, ввести `$hasAnyCriterion`.
5. **`search.html.twig`** — добавить collapse-блок, чекбоксы, бэйдж, кнопку «Сбросить».
6. **Тесты** — Unit-тесты на `CoatingsFilter::normalizeManufacturerIds` (валидный UUID, дубликат, мусор, пустой массив).

## Ссылки

- `app/src/Coatings/Domain/Repository/CoatingsFilter.php` — текущее состояние
- `app/src/Coatings/Infrastructure/Search/CoatingFinder.php` — точка роста для фасетов
- `app/src/Coatings/Infrastructure/Controller/Coating/SearchAction.php` — контроллер расширяется
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/search.html.twig` — UI
- `app/src/Coatings/Application/UseCase/Query/GetPagedManufacturers/` — query для подгрузки списка производителей
