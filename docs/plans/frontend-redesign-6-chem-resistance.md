# Деплой 6 «Химстойкость»: веществоцентричная страница (вещество → стойкие покрытия + управление)

> **Для исполнителя:** пошагово. Тут есть бэкенд (новый read + тонкие экшены) — по нему функциональные тесты (реальная БД), фронт — сборка + браузер. PHP-тесты гоняем для затронутого PHP.

## РЕВИЗИЯ 2026-08-29: мультивыбор веществ (расхождение плана с дизайн-спекой §6.4)

Первый заход реализовал поиск по ОДНОМУ веществу (`substanceId`). Дизайн-макет
(`substance-search.html`) и §6.4 спеки задают ввод НЕСКОЛЬКИХ веществ (чип-инпут:
«Серная кислота 10% ×  + ещё вещество», подпись «Можно добавить несколько веществ»).
Переделываем на мультивыбор. Решения пользователя:

- **Логика отбора — AND**: покрытие в выдаче, только если стойко к КАЖДОМУ выбранному веществу.
- **Вердикт — худший (одна пилюля)**: среди выбранных веществ берём самый слабый грейд
  (R→LR→NR, худший = максимальный вес) и самую ограничивающую (минимальную) температуру.
- Разбивку по веществам (вещество→грейд/темп/assessmentId) всё равно несём в DTO —
  нужна для админ-правки (Task 3) и как задел на альтернативный показ вердикта.

Изменения против исходных тасков:
- `GetCoatingsBySubstanceQuery`: `string $substanceId` → `StringCollection $substanceIds`.
  Handler: пересекает (AND) множества стойких покрытий по каждому веществу; на каждое
  выжившее покрытие считает худший грейд + мин. температуру; собирает разбивку по веществам.
- Новый ChemicalResistance read `GetSubstanceRefs(StringCollection $ids)` → `list<SubstanceRefDTO{id,canonicalName}>`
  (через `SubstanceRepositoryInterface::findAllByIds`) — резолв имён для чипов и разбивки.
- `GetCoatingsBySubstanceQueryResult` дополнительно отдаёт `selectedSubstances` (list<SubstanceRefDTO>)
  для серверного рендера чипов (работает и без JS).
- `CoatingResistanceDTO`: `grade/gradeLabel/maxTemperature` = ХУДШИЙ вердикт; `+ verdicts: list<SubstanceVerdictDTO>`.
- Новый `SubstanceVerdictDTO{substanceId, substanceName, grade, gradeLabel, maxTemperature, assessmentId}`.
- Фронт: одиночный автокомплит → мультивыбор-чипы (новый `substance_multiselect_controller.js`):
  выбор из подсказок / клик по «частым» → добавляет чип + hidden `substanceIds[]` + авто-сабмит
  (`requestSubmit()`); × на чипе — убирает + авто-сабмит; создание вещества (админ) добавляет чип.
  Состояние — в URL (`substanceIds[]`), чипы рендерятся сервером из `selectedSubstances` (no-JS ok).
- Починка «создать вещество»: было ощущение «не сработало», т.к. после выбора/создания не
  было авто-поиска, а свежесозданное вещество без оценок даёт пустую выдачу. Авто-сабмит чинит UX.

**Цель:** страница «Химстойкость»: пользователь ищет вещество (напр. «метанол») → список покрытий, стойких к нему, entity-карточками с вердикт-пилюлей (Стойкое/Ограниченно). Админ на этой же странице: добавляет вещество, добавляет покрытие к веществу (создать assessment), правит химстойкость покрытия (грейд/температура).

**Архитектура:** обратная сторона химстойкости (вещество → покрытия) — новый read-query поверх `AssessmentRepositoryInterface::findAllBySubstance`; гидрация карточек через существующий `CoatingDTOTransformer`. Управление (add/edit assessment, add substance) — переиспупает существующие команды/эндпоинты, добавляем тонкие экшены с редиректом на эту страницу. UI — страница в стиле списка покрытий (поиск + карточки + пагинация), реюз entity-карточек и автокомплитов.

**Tech Stack:** Symfony (CQRS команды/квери, DBAL), Twig, Stimulus (substance/coating autocomplete), Bootstrap, CSS-токены.

**Spec:** `docs/plans/frontend-redesign-design.md` (раздел 6.4). Референс-макет: `.superpowers/brainstorm/*/content/substance-search.html`.

## Контекст (разведка) — что переиспупаем, что новое

Переиспупаем:
- Автокомплит вещества: route `app_cabinet_chemical_resistance_substance_autocomplete` (`?q=`, ответ `{id,canonicalName,cas}`), контроллер `substance_autocomplete_controller.js` (+ inline-создание вещества через `endpointCreate` → `app_api_chemical_resistance_substance_add`).
- Автокомплит покрытия: `app_cabinet_coating_coating_suggest` (+ `async_typeahead`/`async-typeahead` контроллер).
- Вердикт: enum `App\ChemicalResistance\Domain\Aggregate\Assessment\Grade` (R/LR/NR/FS/NT; R=Стойкое, LR=Ограниченно, NR=Нестойкое; `isSuitable()`=R|LR). Цвета бейджей — из `_chem_resistance_row.html.twig` (R success, LR warning, NR danger).
- `AssessmentRepositoryInterface::findAllBySubstance(Uuid $substanceId): Assessment[]` — assessment'ы по веществу (coatingId, grade, maxTemperatureCelsius).
- Команды: `CreateAssessmentCommand`, `UpdateAssessmentCommand` (+ `AssessmentMapper::getValidationCollectionCreate`, `AssessmentInputParser::temperature/noteIds`).
- Гидрация карточек: `CoatingRepository::findByIds` + `CoatingDTOTransformer::fromEntityList` → `CoatingDTO`; entity-card стили (`entity-card.css`), `components/infinite_list.html.twig`.
- Существующий `AddAction`/`UpdateAction` assessment'ов редиректят на страницу правки покрытия — для нашей страницы сделаем тонкие экшены с редиректом назад (те же команды).

Новое:
- Read-query «стойкие покрытия по веществу» (+ result DTO).
- Страница (маршрут + экшен + шаблон) + partial карточек с вердикт-пилюлей.
- Тонкие экшены add/edit assessment с редиректом на страницу вещества (реюз команд).
- 5-я вкладка «Химстойкость» (`bi-droplet`); переименование админ-пункта «Химстойкость» → «Вещества».

## Global Constraints

- Логика (какие покрытия стойкие к веществу) — в домене/квери, не в шаблоне. Вердикт — из `Grade` (не выдумывать).
- Авторизация: просмотр — все авторизованные; добавление/правка assessment и вещества — только `ROLE_ADMIN`, через `*AccessControl` в Application-хендлере (не `#[IsGranted]`). Кнопки под `{% if canEdit %}` — только UX.
- Без JS: поиск вещества и список работают через серверный GET (пагинация-fallback), формы add/edit — обычные POST с редиректом назад. JS (автокомплит, инлайн) — прогрессивное улучшение.
- Реюз, а не дублирование: карточки, автокомплиты, команды assessment, `infinite_list`.
- Верификация фронта — сборка + браузер; PHP (квери/экшены) — функциональные тесты (реальная БД, трейт `authenticateAsSystem()` для админ-гейта).

## Карта файлов

Backend (read):
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/GetResistantCoatings/GetResistantCoatingsQuery.php` (+ Handler) — вещество → map `coatingId → {grade, maxTemperature}` (фильтр suitable по умолчанию; флаг includeAll).
- Create: `app/src/Coatings/Application/UseCase/Query/GetCoatingsBySubstance/GetCoatingsBySubstanceQuery.php` (+ Handler) — оркестрирует: зовёт ChemicalResistance-квери через bus, пагинирует coatingId, гидрирует `CoatingDTO`, собирает result-DTO.
- Create: `app/src/Coatings/Application/DTO/Coatings/CoatingResistanceDTO.php` — `public CoatingDTO $coating; public string $grade; public ?int $maxTemperature;`.

Backend (page + admin):
- Create: `app/src/Coatings/Infrastructure/Controller/Coating/BySubstanceAction.php` — route `app_cabinet_coating_coating_by_substance` GET `/cabinet/coating/coating/by-substance` (+ `?partial=1` для догрузки).
- Create: `app/src/ChemicalResistance/Infrastructure/Controller/Assessment/AddFromSubstanceAction.php` и `UpdateFromSubstanceAction.php` — POST, реюз `CreateAssessmentCommand`/`UpdateAssessmentCommand`, редирект на by-substance страницу (`?substanceId=…`). (Или: параметр `redirect` в существующие Add/Update — решить на Task 3.)

Frontend:
- Create: `app/src/Shared/Infrastructure/Templates/cabinet/chemical_resistance/by_substance.html.twig` — страница.
- Create: `app/src/Shared/Infrastructure/Templates/cabinet/chemical_resistance/_resistant_cards.html.twig` — batch карточек (entity-card + вердикт-пилюля + админ inline-edit).
- Modify: `app/assets/styles/components/entity-card.css` — вердикт-пилюля (или переиспользовать `.b-ok`/`.b-iso`).
- Modify: `base.html.twig` (`nav_items` +«Химстойкость»/`bi-droplet`; «Ещё»/сайдбар — админ-пункт «Химстойкость»→«Вещества»); `_sidebar.html.twig` (админ-пункт переименовать).

---

## Task 1: Backend — read «стойкие покрытия по веществу»

**Files:** `GetResistantCoatingsQuery(+Handler)` (ChemicalResistance), `GetCoatingsBySubstanceQuery(+Handler)` + `CoatingResistanceDTO` (Coatings).

- [ ] **Step 1: `GetResistantCoatingsQuery` (ChemicalResistance)** — вход `substanceId: string`, `includeAll: bool = false`. Handler: `findAllBySubstance(Uuid::fromString(substanceId))` → отфильтровать (`includeAll ? все : grade.isSuitable()`), вернуть `array<string coatingId, array{grade: string, maxTemperature: ?int}>` (или список маленьких DTO). Порядок: R раньше LR (для дефолтной сортировки).

- [ ] **Step 2: `CoatingResistanceDTO` (Coatings)** — `public function __construct(public CoatingDTO $coating, public string $grade, public ?int $maxTemperature) {}`.

- [ ] **Step 3: `GetCoatingsBySubstanceQuery` (Coatings)** — вход `substanceId`, `includeAll`, `page`, `perPage`. Handler:
  - `$map = $bus->ask(new GetResistantCoatingsQuery(substanceId, includeAll))`;
  - `$ids = array_keys($map)`; total = count; срез страницы `$pageIds`;
  - `$coatings = $coatingRepo->findByIds(StringCollection(pageIds))`; `$dtos = $transformer->fromEntityList($coatings)` (map by id, сохранить порядок `$pageIds`);
  - собрать `CoatingResistanceDTO[]` (coating + map[id].grade + map[id].maxTemperature); заполнить `coating.matchedSubstances` не обязательно (вердикт несём отдельно);
  - вернуть `{items: CoatingResistanceDTO[], total, page, perPage, totalPages}`.

- [ ] **Step 4: Функциональный тест** (`tests/Functional/Coatings/...GetCoatingsBySubstance...` или Unit для GetResistantCoatings с фикстурой assessment'ов): по substanceId возвращаются покрытия с грейдом; `includeAll=false` не отдаёт NR; пагинация; порядок R→LR. (Реальная БД; трейт auth при необходимости.)

- [ ] **Step 5: Коммит.** `git commit -m "Обратный поиск химстойкости: read «стойкие покрытия по веществу» с грейдом и пагинацией"`

---

## Task 2: Страница «Химстойкость» + карточки с вердиктом

**Files:** `BySubstanceAction.php`, `by_substance.html.twig`, `_resistant_cards.html.twig`, `entity-card.css`.

- [ ] **Step 1: `BySubstanceAction`** — GET `/cabinet/coating/coating/by-substance`. Читает `substanceId`, `includeAll`, `page`. Если `substanceId` пуст — рендер пустого состояния (поиск + подсказка + частые вещества опц.). Иначе — `GetCoatingsBySubstanceQuery` → рендер страницы (или `_resistant_cards` при `?partial=1`). Резолв названия выбранного вещества для чипа (autocomplete by-id? есть `substance_autocomplete`; для метки — можно передать `substanceName` из query или дозапросить). Доступ — все авторизованные (просмотр).

- [ ] **Step 2: `by_substance.html.twig`** (структура как список покрытий):
  - app-bar/заголовок «Химстойкость»;
  - поиск вещества: input с `data-controller="substance-autocomplete"` (endpoint autocomplete; для админа — `endpointCreate` = добавить вещество), выбранное вещество как чип; submit ставит `?substanceId=…` (GET, shareable в URL — [[feedback_filter_state_in_url]]);
  - тумблер «Только стойкие / + ограниченно» (includeAll) — опц.;
  - результаты: `infinite_list` с `items_template: _resistant_cards.html.twig`, `wrapper_class: 'ecard-grid'`;
  - в конце (для админа) — блок «Добавить покрытие к этому веществу» (Task 3).

- [ ] **Step 3: `_resistant_cards.html.twig`** — batch: для каждого `item` (CoatingResistanceDTO) — entity-card:
  - медиа-плитка монограма связующего (как в списке покрытий, `_coating_cards_batch` — вынести общий кусок при желании; пока продублировать минимально);
  - вердикт-пилюля вверху карточки: `item.grade` → R «Стойкое» (ok), LR «Ограниченно» (warn) + `maxTemperature` («до {N}°C»);
  - заголовок (клик → превью покрытия, `coating-preview-loader#open` + `data-coating-id`);
  - для админа — inline-edit (Task 3).
- [ ] **Step 4: CSS вердикт-пилюли** — переиспользовать `.ecard-badges .b-ok` (ok) + добавить `.b-warn` (warn-subtle/warn) для LR, если нет.
- [ ] **Step 5: Сборка + `lint:twig` + проверка**: поиск метанола → карточки стойких покрытий с пилюлей; пустое состояние; пагинация; превью открывается.
- [ ] **Step 6: Коммит.** `git commit -m "Страница «Химстойкость»: поиск вещества → стойкие покрытия карточками с вердикт-пилюлей"`

---

## Task 3: Админ — добавить/править химстойкость и вещество

**Files:** `AddFromSubstanceAction.php`, `UpdateFromSubstanceAction.php` (ChemicalResistance), правки в `by_substance.html.twig` / `_resistant_cards.html.twig`.

- [ ] **Step 1: Тонкие экшены с редиректом назад.**
  - `AddFromSubstanceAction` POST `/cabinet/chemical-resistance/by-substance/assessment/create`: тело `substanceId, coatingId, grade, maxTemperatureCelsius`; валидация `AssessmentMapper::getValidationCollectionCreate`; `CreateAssessmentCommand(...)`; редирект `app_cabinet_coating_coating_by_substance?substanceId=…`. Гейт — админ (AccessControl в хендлере команды уже есть; экшен тонкий).
  - `UpdateFromSubstanceAction` POST `/cabinet/chemical-resistance/by-substance/assessment/{assessmentId}/update`: тело `grade, maxTemperatureCelsius`; `UpdateAssessmentCommand(...)`; редирект назад. (Опц. Delete — «убрать покрытие из вещества».)
  - (Альтернатива, если не плодить экшены: добавить в существующие `AddAction`/`UpdateAction` опциональный `redirectRoute`/`substanceId` и редиректить туда. Решить в начале Task 3; тонкие экшены — предпочтительнее, existing не трогаем.)

- [ ] **Step 2: UI «Добавить покрытие к веществу»** (админ, в конце списка на `by_substance.html.twig`): форма — автокомплит покрытия (`app_cabinet_coating_coating_suggest`) + select грейда (Grade cases с рус. лейблами) + температура (число, °C) → POST в `AddFromSubstanceAction`. Скрытый `substanceId`.

- [ ] **Step 3: UI inline-edit на карточке** (админ): kebab «⋮» или кнопка «Править химстойкость» → маленькая форма (грейд select + температура) → POST в `UpdateFromSubstanceAction` c `assessmentId`. `assessmentId` нужно прокинуть в карточку — добавить в `GetResistantCoatings`/DTO (расширить map значением `assessmentId`).

- [ ] **Step 4: Добавление вещества (админ)** — через существующий inline-create в `substance_autocomplete` (endpointCreate → `app_api_chemical_resistance_substance_add`); подтвердить, что контроллер после создания выбирает новое вещество. Кнопка/подсказка «Добавить вещество» рядом с поиском под `{% if canEdit %}`.

- [ ] **Step 5: Функциональные тесты** (реальная БД, `authenticateAsSystem()`): add assessment из by-substance → появляется в списke вещества; update меняет грейд/температуру; не-админ получает Forbidden. 
- [ ] **Step 6: Проверка в браузере** (админ и не-админ): добавление покрытия к веществу, правка грейда/температуры, добавление вещества; не-админ кнопок не видит и POST упирается в гейт.
- [ ] **Step 7: Коммит.** `git commit -m "Химстойкость: админ добавляет покрытие к веществу, правит грейд/температуру и заводит вещество прямо на странице"`

---

## Task 4: Навигация — вкладка «Химстойкость» + переименование справочника

**Files:** `base.html.twig` (`nav_items` + «Ещё»), `_shell/_sidebar.html.twig`.

- [ ] **Step 1: Добавить 5-й пункт nav** в `nav_items` (base.html.twig): `{ key: 'chem', label: 'Химстойкость', icon: 'bi-droplet', route: 'app_cabinet_coating_coating_by_substance', prefix: 'app_cabinet_coating_coating_by_substance' }`. Появится в нижней таб-панели (Покрытия · Системы · Документы · Химстойкость · Ещё) и в сайдбаре.
  - Проверить активность: prefix by-substance не должен ловиться префиксом `app_cabinet_coating_coating` (Покрытия) — у «Покрытия» prefix `app_cabinet_coating_coating_list`? Сейчас prefix Покрытий = `app_cabinet_coating_coating` (ловит и by_substance!). Уточнить: сузить prefix Покрытий до `app_cabinet_coating_coating_list`/`_update`/… или сделать точную проверку, чтобы by-substance подсвечивал «Химстойкость», а не «Покрытия». (Правка `nav_items` active-логики.)

- [ ] **Step 2: Переименовать админ-справочник** «Химстойкость» → «Вещества» в «Ещё» (base.html.twig) и сайдбаре (`_sidebar.html.twig`) — пункт `app_cabinet_chemical_resistance_substance_list`. Иконку справочника оставить `bi-eyedropper` (вкладка — `bi-droplet`), чтобы не путать.

- [ ] **Step 3: Сборка + проверка**: вкладка «Химстойкость» ведёт на страницу; активный пункт подсвечивается корректно (by-substance → «Химстойкость», список покрытий → «Покрытия»); админ-«Вещества» открывает справочник.
- [ ] **Step 4: Коммит.** `git commit -m "Вкладка «Химстойкость» (поиск по веществу) в навигации; админ-справочник переименован в «Вещества»"`

---

## Task 5: Финальная верификация

- [ ] **Step 1: Сборка** `cd app && yarn dev`.
- [ ] **Step 2: PHP-тесты** затронутого (квери + assessment-экшены): `vendor/bin/phpunit` соответствующих директорий (unit на хосте, functional в контейнере по [[reference_test_run_env]]).
- [ ] **Step 3: `lint:twig`** новых шаблонов.
- [ ] **Step 4: Свип** (обе темы, мобайл+десктоп, админ и не-админ): поиск вещества → стойкие покрытия с пилюлей; пустое состояние; превью; админ add/edit/add-substance; активная вкладка; фильтры/списки покрытий не задеты.

## Self-review (покрытие спеки 6.4 + кейс пользователя)

- Вещество → стойкие покрытия с вердиктом → Task 1–2. Админ add coating/edit assessment/add substance на той же странице → Task 3. 5-я вкладка + переименование справочника → Task 4.
- Реюз: автокомплиты, `Grade`, `findAllBySubstance`, команды assessment, entity-card, infinite_list (разведка подтвердила). Новое минимально: 1 read-цепочка + тонкие экшены + страница.

## Открытые точки

- **Prefix активной вкладки**: `app_cabinet_coating_coating` (Покрытия) префиксно ловит `..._by_substance` — на Task 4 Step 1 сузить логику active, иначе by-substance подсветит и «Покрытия», и «Химстойкость».
- **Показывать NR/все грейды** админу (toggle includeAll) — для управления. Дефолт — R/LR. Подтвердить, нужен ли admin-toggle «показать все оценки».
- **Тонкие экшены vs redirect-параметр** в существующие Add/Update — выбрать на Task 3 Step 1 (по умолчанию — тонкие экшены, existing не трогаем).
- **Форма редактирования на карточке**: inline мини-форма vs общий bottom-sheet/модалка редактирования assessment — уточнить UX на Task 3 Step 3 (проще inline мини-форма в карточке под `{% if canEdit %}`).
