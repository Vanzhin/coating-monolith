# План: Inquiries — подбор системы по параметрам (резолв веществ + запрос к Coatings)

Деплой 2. Шаг «подбор» процессора типа `подбор_системы` ([план 3](inquiries-3-engine-and-storage.md)). Соседи: [1-экстрактор](inquiries-1-extractor-netangels.md), [4-веб](inquiries-4-web.md).

## Идея

Из распознанных параметров (данные Inquiries) получить список систем — **НЕ строя доменный фильтр Coatings внутри Inquiries**. Граница (принцип: Inquiries не знает чужих доменов, пересекает только через Application-запросы соседей → DTO):

- **Сборка `CoatingSystemsFilter` остаётся в Coatings.** Coatings публикует Application-запрос «подбор систем по параметрам»: принимает **примитивы** и ВНУТРИ строит свой фильтр + зовёт `findByFilter`, отдаёт **DTO систем**. Enum'ы и фильтр — только в Coatings.
- **Резолв веществ имя→id — в ChemicalResistance**: его Application-запрос `SubstanceAutocompleteQuery` → `SubstanceDTO{id,name}`.
- **Inquiries-процессор оркеструет**: берёт распознанные параметры (свои строки/числа) → резолвит вещества (ChemResistance query) → передаёт примитивы + resolved id в запрос Coatings → получает DTO систем = «ответ» для `Inquiry`. Ноль доменных типов Coatings/ChemicalResistance в Inquiries.

## Contracts

- **Coatings (новый Application-запрос)** `SearchCoatingSystemsByParameters`:
  - вход — нейтральные примитивы (Coatings-published input DTO или аргументы): `substrates: list<string>`, `environment: ?string`, `standard/category/durability: ?string`, `substanceIds: list<string>`, `substanceTemperature: ?int`, `thermalTemperature: ?int`, `thermalEnvironment: ?string`, `thermalIncludingPeak: bool`, `applicationMinTemp {from,to}`, `minApplicationTimeAt20 {from,to}`, `search: ?string`, пагинация;
  - внутри — строит `CoatingSystemsFilter` (зеркало `CoatingSystemListRequestMapper`, но из DTO, а не HTTP-Request; невалидные значения глотает через `tryFrom`), `findByFilter`;
  - выход — `list<CoatingSystemSuggestDTO>` (id + подпись) + метаданные пагинации.
- **ChemicalResistance (есть)** `SubstanceAutocompleteQuery(q, limit)` → `list<SubstanceDTO{id, canonicalName}>`.

## Сторона Inquiries (оркестрация, без доменов соседей)

- **Порт** `SubstanceResolver` (Inquiries/Application) + адаптер `ChemicalResistanceSubstanceResolver` (Infrastructure) — оборачивает `SubstanceAutocompleteQueryHandler`, имя → первый `id` или null. Тестируемо фейком.
- **Шаг подбора в процессоре**: распознанные параметры → примитивы запроса Coatings; `chemicalResistance.substances` → через `SubstanceResolver` в `substanceIds` (не найденные → в отчёт `unresolved`); `chemicalResistance.maxTemperature` (рабочая 40) → `substanceTemperature`; `thermal.*` → `thermalTemperature/thermalEnvironment` (+ `thermalIncludingPeak` = есть ли `thermal.peakTemperature`); пик химии пока информативно (у фильтра химстойкости пика нет).
- `specifiedCoatings` в подбор НЕ идёт (информационное, в показ).
- Результат шага: список систем (DTO Coatings) + отчёт резолва (resolved/unresolved вещества) → процессор кладёт в `Inquiry` как «ответ».

## Файлы

- Coatings: `Application/UseCase/Query/SearchCoatingSystemsByParameters/{Query, Handler, Result}.php` (+ input DTO, если решим отдельным; reuse `CoatingSystemSuggestDTO`). Логику фильтра — из существующего `CoatingSystemListRequestMapper` (вынести общий билдер «примитивы → CoatingSystemsFilter», чтобы HTTP-маппер и этот запрос не дублировались).
- Inquiries: `Application/Port/SubstanceResolver.php`, `Infrastructure/Resolver/ChemicalResistanceSubstanceResolver.php`, шаг подбора в процессоре (`Application/Service/...`).

## Тесты

- Coatings: functional `SearchCoatingSystemsByParameters` на реальной БД — примитивы → системы; каскад (category/durability без standard → null); невалидное значение проглочено.
- Inquiries: functional `ChemicalResistanceSubstanceResolver` — известное вещество → id, несуществующее → null (сеять одно вещество, как в `SearchIntegrationTest`).
- Inquiries: unit шага подбора с фейками (резолвер + запрос Coatings) — параметры → примитивы корректно, substanceTemperature из maxTemperature, unresolved в отчёт.

## Развилки

- Вход запроса Coatings — отдельный **input DTO** vs аргументы-примитивы. Предлагаю input DTO (читаемо, расширяемо).
- **Общий билдер «примитивы → CoatingSystemsFilter»** в Coatings, чтобы HTTP-маппер (`CoatingSystemListRequestMapper`) и новый запрос звали одно — не дублировать маппинг.
- Порог fuzzy-матча вещества (топ-1 vs мин. похожесть) — чтобы «азот» не цеплял мусор.
- Несколько кандидатов на имя — топ-1 + альтернативы в отчёт (подсказка инженеру).
