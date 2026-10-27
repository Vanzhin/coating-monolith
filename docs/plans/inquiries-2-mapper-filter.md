# План: Inquiries — маппер JSON-параметров в CoatingSystemsFilter

Деплой 2. Соседи: [1-экстрактор](inquiries-1-extractor-netangels.md), [3-движок](inquiries-3-engine-and-storage.md), [4-веб](inquiries-4-web.md). Контракт фильтра — в [podbor-po-pismu.md](podbor-po-pismu.md) (раздел «контракт фильтра»).

## Идея

Превратить `ExtractedParameters` (из [плана 1](inquiries-1-extractor-netangels.md)) в `CoatingSystemsFilter` для существующего `findByFilter` — плюс **резолв имён веществ в id** (через ChemicalResistance). Только shape + резолв, без бизнес-логики подбора: каскад (category/durability без standard) уже в конструкторе `CoatingSystemsFilter`.

Дополнительно собирает **отчёт маппинга**: что из имён веществ нашлось, что нет, какие значения отброшены — для показа инженеру («что извлечено vs что реально ушло в запрос»).

## Решения

- **Маппер** `FilterFromParametersMapper` (Inquiries/Application/Service): `ExtractedParameters` → `CoatingSystemsFilter`. substrates/environment/standard/category/durability/thermal* кладём как есть (enum'ы уже валидны); каскад не дублируем.
- **Резолв веществ**: имена `substances` → `substanceIds`. Через **публичный запрос ChemicalResistance** (`SubstancesFilter::search` / `MatchSubstancesForSearch`, fuzzy по russian-стеммеру — см. [[project_podbor_po_pismu_extraction]]), по одному имени → лучший матч. Кросс-контекст — только через Application-запрос ChemicalResistance, не лезем в его потроха.
- **`specifiedCoatings` в фильтр НЕ идёт** — информационное поле (клиент уже задал покрытие), тащим в результат для показа, не в `findByFilter`.
- **Единицы**: `minApplicationTimeAt20` домен хранит в минутах — маппер строит доменный фильтр в минутах; перевод в ЧАСЫ для URL-ссылки — в [плане 4](inquiries-4-web.md).
- **Отчёт** `FilterMappingResult { CoatingSystemsFilter, resolvedSubstances: map<name,id>, unresolvedSubstances: list<name>, droppedValues: list<string> }`.

## Границы

- Резолв веществ — единственная «умная» операция; всё остальное — прямой перенос. Если имя не нашлось в реестре — не падаем: кладём в `unresolvedSubstances`, ось химстойкости для него не активируем (инженер увидит «вещество X в базе не найдено»).
- Вещество → `substanceIds` (AND по веществам в фильтре, как в текущем фасете).

## Файлы

- `Inquiries/Application/Service/FilterFromParametersMapper.php`
- `Inquiries/Application/DTO/FilterMappingResult.php`
- Резолвер: тонкий вызов существующего запроса ChemicalResistance (новый класс не плодим, если хватает `SubstanceRepository::findByFilter` с `SubstancesFilter(search:)`).

## Тесты

- Unit round-trip `ExtractedParameters` → `CoatingSystemsFilter`: поля на месте, каскад отрабатывает (category/durability без standard → null).
- Functional резолв веществ на реальной БД: известное вещество → id; несуществующее → в `unresolvedSubstances`, фильтр валиден без него.
- Unit: `specifiedCoatings` не попадает в фильтр, но есть в результате.

## Развилки

- Порог уверенности fuzzy-матча вещества: брать топ-1 всегда, или требовать мин. похожесть (иначе «азот» матчит мусор)? Предлагаю порог + «не нашлось» при низком скоре.
- Несколько кандидатов на одно имя — топ-1 + в отчёт альтернативы (на будущее для подсказки инженеру).
