# Цвета и блеск покрытия (Деплой 1)

> Многоэтапная задача, два деплоя → два плана. Это **План 1**. Соседний: `coating-colors-gloss-2.md`
> (выбор цвета в слое системы) — начинать только после того, как этот деплой в проде.

## Задача

У покрытия появляются:
- **Возможные цвета** — список цветов, в которых покрытие может быть произведено. Цвет — переиспользуемый
  справочный объект (общий на все покрытия), заводится и выбирается по аналогии с тегами.
- **Блеск (gloss)** — единственное значение на покрытие (enum, 5 уровней).
- **Колеруемость (`isTintable`)** — флаг «можно получить любой цвет». У колеруемого покрытия список
  возможных цветов **может быть пустым**; у не-колеруемого — обязателен минимум один цвет.

Цвет = `{ name, ral?, hex }`: название (свободный ярлык), опциональное соответствие каталогу RAL Classic,
и hex — реальный цвет (для визуального свотча). Пользователь всегда видит цвет глазами, а не только текст.

## Подход

**Цвет зеркалит `Tag` один-в-один** (агрегат-рут `Color` в контексте `Coatings`, M2M с покрытием,
suggest-эндпоинт, инлайн-создание, read-only список без правки/удаления). Отличия от тега:
- у цвета три поля вместо одного (`name`, `ral?`, `hex`), поэтому инлайн-создание — не «+ Создать» внутри
  Tagify, а **модалка** (образец — `surface_treatment_modal`);
- источник цвета для свотча — RAL: при выборе RAL из каталога `hex` берётся из эталона и не редактируется;
  кастомный цвет (без RAL) — `hex` задаётся нативным color-input.

**Инвариант «RAL ↔ hex не расходятся» — доменный.** RAL Classic (код → каноничное имя → hex) — это доменные
reference-данные (стандарт, часть ubiquitous language, а не техника хранения). Кладём каталог в домен как
реестр `RalClassicPalette`. При заданном RAL конструктор `Color` **выводит** hex из эталона — красный hex к
серому RAL физически не пришить. «Название говорит красный, а RAL серый» инвариантом не ловим (домен не знает
семантику названий, мультиязычность, бренд-имена) — гасим структурно (выбор из палитры RAL подставляет имя+RAL+hex
одним куском) и визуально (свотч виден везде из `hex`, т.е. реальный цвет).

**Блеск и колеруемость** — прямые поля покрытия по чек-листу «добавить поле в форму покрытия» из `CLAUDE.md`.
Инвариант «не-колеруемое ⇒ ≥1 цвет» — cross-field (isTintable + colors), живёт в агрегате `Coating`.

## Принятые решения (развилки согласованы)

- **Блеск** — enum, 5 уровней: `глубокоматовый / матовый / полуматовый / полуглянцевый / глянцевый`.
  Единственное значение на покрытие. В домене **nullable** (`?Gloss`): у существующих покрытий значения нет,
  null = «не указан», навязывать дефолт не выдумываем. Форма не форсит.
- **Цвет** — общий справочник, **только создание** (шарится между покрытиями). Правки/удаления нет — как у
  тегов (read-only список). Ошибся в hex — заводишь новый цвет.
- **Источник hex** — из RAL по встроенному каталогу; hex хранится **на самом цвете** (образец есть всегда,
  включая кастом без RAL). При заданном RAL hex зафиксирован эталоном (не редактируется); правится только у кастома.
- **RAL** валидируется как RAL Classic — по факту через членство в каталоге `RalClassicPalette` (неизвестный
  код → `AppException`).
- **Название** всегда редактируемо — выбор из RAL подставляет каноничное имя как заготовку, пользователь может
  переписать. Пара RAL+hex при этом фиксирована.
- **Уникальность — по паре (название, hex)**, название регистронезависимо. Одинаковое имя с разным hex —
  можно; одинаковый hex с разным именем — можно; полный дубль — нельзя. Отдельного класса-спецификации НЕТ:
  гарантия — уникальный индекс БД `(lower(name), hex)`, плюс проверка `findOneByNameAndHex` в
  `CreateColorCommandHandler` → `AppException` (осознанно кладём в handler, чтобы не плодить спеку).
- **id — передаётся**, не генерится внутри: конструктор берёт `Uuid $id`, генерация `UuidService::generateUuid()` в создателе (как `Coating`).
- **Поиск** — ILIKE/trigram по названию + точное совпадение по RAL-коду (пользователь может печатать «RAL 7040»).
  Полноценный FTS-триггер как у тегов для маленького справочника — перебор.
- **Колеруемость** — bool-флаг `isTintable`; список возможных цветов у колеруемого может быть пустым.

## Домен и инварианты

### `Coatings/Domain/Aggregate/Color/` (новый агрегат, зеркало `Tag`)

- **`Color extends Aggregate`** — поля `public readonly Uuid $id` (передаётся в конструктор, не генерится),
  `string $name`, `?string $ral`, `string $hex`, обратная `Collection<Coating> $coatings`. `getId(): string` →
  `$this->id->toRfc4122()`. Конструктор `(Uuid $id, string $name, ?string $ralCode = null, ?string $hex = null)`:
  - `name` — trim, непусто, `AssertService::maxLength(100)`.
  - если `ralCode !== null` → `RalClassicPalette::require($ralCode)` (неизвестный → `AppException`); `hex` **выводится**
    из эталона (`ralColor->hex`), клиентский `$hex` игнорируется; `ral = ralColor->code`.
  - если `ralCode === null` → `hex` обязателен и валиден (`#RRGGBB`, мелкий VO `HexColor`); `ral = null`.
  - уникальность (name, hex) в конструкторе НЕ проверяется — это делает create-handler + индекс БД.
- **`RalColor`** — `final readonly` VO: `code` (например `RAL 7040`), `name` (каноничное RU-имя), `hex`.
- **`RalClassicPalette`** — доменный реестр: приватная константа-датасет (~213 строк: код, имя RU, hex),
  методы `tryGet(string $code): ?RalColor`, `require(string $code): RalColor` (throw `AppException`),
  `all(): list<RalColor>`, `search(string $q): list<RalColor>` (по коду и имени — для модалки).
  Датасет RAL Classic (приближённые sRGB) встраивается при реализации из публичного стандарта.
- **`Repository/ColorRepositoryInterface`** — `add`, `findOneById`, `findOneByNameAndHex(name, hex): ?Color` (ci
  по имени, для контроля уникальности пары), `findByIds(StringCollection): list<Color>` (резолв цветов при
  сохранении покрытия — без отдельного Fetcher-сервиса), `suggest(query, limit): list<Color>` (typeahead:
  подстрочный ci-поиск по `name` ИЛИ `ral`). `findByFilter(ColorsFilter)` — для read-only списка (позже).
- **`Repository/ColorsFilter`** — `pager`, `name` (для списка, позже).

### `Coatings/Domain/Aggregate/Coating/`

- **`Gloss`** (enum, backed string): `DEAD_MATTE, MATTE, SEMI_MATTE, SEMI_GLOSS, GLOSS` + `label(): string`
  (RU-подписи). По образцу `CoatingBase`/`RecoatingInterpolationModel`.
- **`Coating`** — новые поля/методы:
  - `?Gloss $gloss = null` + `getGloss()/setGloss(?Gloss)` (raise `CoatingMutated`).
  - `bool $isTintable = false` (default) + `isTintable(): bool`.
  - `Collection<Color> $possibleColors` (init в конструкторе) + `getPossibleColors(): Collection`.
  - **Атомарный** `applyColorScheme(bool $isTintable, Color ...$colors): void` — ставит колеруемость и список
    вместе и проверяет инвариант «не-колеруемое ⇒ ≥1 цвет» ровно один раз (`if (!$isTintable && [] === $colors)
    throw AppException`). Отдельные `setIsTintable`/`replaceColors` НЕ делаем: дефолт (false + пусто) транзитно
    невалиден, эагерная проверка в раздельных сеттерах сломала бы порядок конструирования. Параметр цветов —
    вариадик, не `array`.

## Файлы

### Новые — стек `Color` (зеркало `Tag`)

- `app/src/Coatings/Domain/Aggregate/Color/Color.php`
- `app/src/Coatings/Domain/Aggregate/Color/RalColor.php`
- `app/src/Coatings/Domain/Aggregate/Color/RalClassicPalette.php`
- `app/src/Coatings/Domain/Aggregate/Color/HexColor.php` — мелкий VO-валидатор hex (или инвариант прямо в `Color`).
- `app/src/Coatings/Domain/Repository/ColorRepositoryInterface.php`
- `app/src/Coatings/Domain/Repository/ColorsFilter.php` (для списка, позже)
- `app/src/Coatings/Infrastructure/Repository/ColorRepository.php` — здесь же метод `suggest` (без отдельного
  `ColorFinder`: маленький справочник, обычный `LIKE` по `name`/`ral`).
- `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Color.Color.orm.xml` — таблица `coatings_coating_color`,
  id `uuid` generator NONE, `name`(100), `ral`(20, nullable), `hex`(7). Уникальность — функциональным индексом
  `(lower(name), hex)` в миграции (не в ORM). Inverse M2M `coatings` (mapped-by `possibleColors`) добавляется
  вместе с owning-стороной на `Coating` (шаг интеграции покрытия), а не сейчас.
- `app/src/Coatings/Application/DTO/Colors/ColorDTO.php` — **полный** DTO чтения `{string $id; string $name;
  ?string $ral; string $hex;}` (строится только из сущности, поэтому name/hex всегда заданы; ral опционален).
- `app/src/Coatings/Application/DTO/Colors/ColorDTOTransformer.php` — `fromEntity/fromEntityList`.
- `app/src/Coatings/Application/UseCase/Query/SuggestColors/{SuggestColorsQuery,Handler,Result}.php`
- `app/src/Coatings/Application/UseCase/Command/CreateColor/{CreateColorCommand,Handler,Result}.php` — генерит
  `Uuid` (`UuidService::generateUuid()`), строит `Color`, проверяет дубль пары через `findOneByNameAndHex(name, hex)`
  → `AppException`, `repo->add()` (persist+flush), отдаёт `{id,name,ral,hex}`.
- `app/src/Coatings/Infrastructure/Controller/Color/SuggestColorsAction.php` — `GET /cabinet/coating/color/suggest`,
  `ROLE_ADMIN`, `q,limit` → JSON `[{id,name,ral,hex}]`.
- `app/src/Coatings/Infrastructure/Controller/Color/CreateColorAction.php` — `POST /cabinet/coating/color`,
  `ROLE_ADMIN`, JSON `{name, ral?, hex?}` → 201 `{id,name,ral,hex}` / 422 `{message}`.
- `app/src/Coatings/Infrastructure/Controller/Color/RalPaletteAction.php` — `GET /cabinet/coating/color/ral?q=`,
  `ROLE_ADMIN` → `RalClassicPalette::search(q)` как JSON `[{code,name,hex}]` для сетки в модалке (ленивый поиск).
- `app/src/Coatings/Infrastructure/Controller/Color/ListAction.php` + шаблон
  `admin/coating/color/list.html.twig` — read-only список цветов (со свотчами), как `tag/list`.
- `app/assets/controllers/coating_colors_controller.js` — Tagify по образцу `coating_tags_controller.js`, но
  рендер чипа/подсказки со свотчем (кружок `background:hex`); «+ Создать» открывает модалку, а не POST напрямую.
  Скрытые инпуты с ПОЛНЫМИ данными цвета — `colors[][id]`, `colors[][name]`, `colors[][ral]`, `colors[][hex]`
  (Tagify их знает) — чтобы `CoatingDTO` нёс полные `ColorDTO`, а не id.
- `app/assets/controllers/color_create_modal_controller.js` — модалка (образец `surface_treatment_modal`):
  режим «Из RAL» (поиск по `/ral`, сетка свотчей, клик → name(заготовка)+ral+hex, hex read-only) и режим
  «Кастомный» (name + color-input hex); submit → POST `/color` → на 201 добавляет цвет в `coating-colors` typeahead
  и закрывает; 422 → показать message.
- `app/assets/styles/components/color-swatch.css` — стили свотча/чипа цвета (подключить через `@import` в `app.css`).

### Правим

- `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` — поля `gloss`, `isTintable`, `possibleColors` + методы +
  инвариант `assertColorsConsistentWithTintable` (см. выше).
- `app/src/Coatings/Domain/Service/CoatingMaker.php` — `make()` доп. параметры `array $colorIds`, `?Gloss $gloss`,
  `bool $isTintable`; резолв `ColorRepositoryInterface::findByIds`, затем
  `$coating->applyColorScheme($isTintable, ...$colors)` и `setGloss($gloss)`.
- `app/src/Coatings/Application/UseCase/Command/CreateCoating/{CreateCoatingCommand,Handler}.php` и
  `.../UpdateCoating/{UpdateCoatingCommand,Handler}.php` — пробросить `colorIds`, `gloss`, `isTintable`;
  резолв `findByIds` + `applyColorScheme($isTintable, ...$colors)` + `setGloss`.
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php` — `+ list<ColorDTO> $possibleColors` (ПОЛНЫЕ
  `ColorDTO`, не id-список), `?string $gloss`, `bool $isTintable`.
- `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`:
  - `buildCoatingDtoFromInputData` — собрать полные `ColorDTO` из `inputData['colors']` (`id/name/ral/hex`),
    `gloss`, `isTintable`.
  - `buildInputDataFromDto` — разложить `possibleColors` (полные) обратно в `colors[]` (`id/name/ral/hex`),
    `glossValue`, `isTintable`. Отдельный hydrator НЕ нужен: полные DTO уже в `CoatingDTO`.
  - `getValidationCollectionCoating` — `colors`: `Optional(All(Collection(id: NotBlank+Uuid, name: NotBlank,
    hex: NotBlank, ral: Optional)))`; `gloss`: `Optional(Choice(Gloss values))`; `isTintable`: bool. **Инвариант
    пустоты списка — не здесь** (домен). На persist источник истины — сущность по id (`findByIds`); posted
    name/hex — только для UX/перерендера.
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` — `fromEntity` заполняет `colors/gloss/isTintable`.
- `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` — M2M `possibleColors`
  (owning, join-table `coatings_coating_coating_color`, `coating_id`/`color_id`, `order-by name ASC`);
  `gloss` (`type="string"` + `enum-type="App\Coatings\Domain\Aggregate\Coating\Gloss"`, nullable);
  `isTintable` (`type="boolean"`, column `is_tintable`).
- `app/src/Coatings/Infrastructure/Controller/Coating/AddAction.php` / `UpdateAction.php` — в контекст формы
  `glossOptions` (Gloss cases). Предвыбранные цвета берутся из `inputData.colors` (полные), без hydrator.
- `admin/coating/coating/form.html.twig` — блок «Возможные цвета» (typeahead + кнопка «Создать цвет» + модалка),
  select «Блеск», чекбокс «Колеруемое».
- `admin/coating/coating/_coating_preview.html.twig` + карточки покрытия — показ цветов чипами со свотчем, блеска,
  бейджа «колеруемое».
- `app/config/packages/doctrine.yaml` — **не трогаем**: новых JSON DBAL-типов нет (`Color` — таблица; `Gloss`
  через нативный Doctrine `enum-type`, как `CoatingBase`).
- Идемпотентная миграция `app/src/Shared/Infrastructure/Database/Migrations/Version*.php`:
  - `CREATE TABLE IF NOT EXISTS coatings_coating_color (id, name, ral null, hex)`;
  - `CREATE UNIQUE INDEX IF NOT EXISTS ... ON coatings_coating_color (lower(name), hex)`;
  - `CREATE TABLE IF NOT EXISTS coatings_coating_coating_color (coating_id, color_id, PK+FK)`;
  - `ALTER TABLE coatings_coating ADD COLUMN IF NOT EXISTS gloss varchar null`,
    `... ADD COLUMN IF NOT EXISTS is_tintable boolean NOT NULL DEFAULT false`.

## Поведение (форма покрытия)

1. **Возможные цвета** — печатаешь «серый» → подсказки из справочника цветов (чипы со свотчем); «RAL 7040» →
   поиск по коду. Выбираешь один/несколько. Нет нужного → «Создать цвет» открывает модалку.
2. **Модалка создания** — «Из RAL»: поиск/сетка свотчей, клик подставляет name(заготовка)+ral+hex (hex read-only);
   «Кастомный»: name + color-input hex. Submit пишет цвет отдельным POST, сразу возвращает id, чип добавляется в
   typeahead. На момент сохранения покрытия все цвета уже в БД (как теги).
3. **Блеск** — select (пусто допустимо). **Колеруемое** — чекбокс.
4. Сохранение: `colors[][id]` + `gloss` + `isTintable` → DTO → домен. Не-колеруемое с пустым списком → 422 из домена,
   рендерится в форме как `alert-danger`.

## Тесты

- Unit `tests/Unit/Coatings/Domain/Aggregate/Color/ColorTest.php`: RAL-режим выводит hex из эталона; неизвестный
  RAL-код → `AppException`; кастом без валидного hex → `AppException`; пустое имя → `AppException`.
- Unit `RalClassicPaletteTest`: `require`/`tryGet`/`search`.
- Unit `tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingColorsTintableTest.php`: не-колеруемое + пустой список
  → throw; колеруемое + пустой список → ok; снятие флага при пустом списке → throw.
- Functional (реальная БД): `CreateColorActionTest` (201; дубль пары name+hex → 422; то же имя с другим hex → 201),
  `SuggestColorsActionTest`, `Create/UpdateCoating` с цветами+блеском+колеруемостью.
- Mapper round-trip: `build → decompose → build` с новыми полями.
- JS/Twig — ручная проверка в браузере.

## Проверка

- `./run check` (cs-fixer, phpstan, unit, functional в изолированном стеке) — зелёно.
- `cd app && yarn dev` (новые JS-контроллеры + CSS + Twig).
- Браузер: создание цвета из RAL и кастома; свотчи в подсказках/чипах/превью; блеск и колеруемость сохраняются;
  не-колеруемое без цветов не сохраняется (ошибка из домена).

## Риски / на что смотреть

- **Датасет RAL Classic** — нужен корректный ~213-строчный список (код, RU-имя, приближённый sRGB hex). Источник и
  точность hex согласовать при реализации.
- **Миграция существующих покрытий** — `gloss=null`, `is_tintable=false`, список цветов пуст. Инвариант «≥1 цвет»
  сработает только при **редактировании** старого не-колеруемого покрытия (админ будет вынужден добавить цвет) —
  это осознанное обогащение данных, не ломает существующие записи.
- **Свотч — новый UI-элемент**, но неизбежный (суть фичи). Держать минимальным, в тон серым чипам, без новых
  цветовых токенов кроме самого `background:hex`.
- **Порядок сеттеров** в `Coating`/`CoatingMaker`: `isTintable` до `possibleColors`, иначе инвариант сработает
  не на том состоянии.

## Дальше

После деплоя — `coating-colors-gloss-2.md`: выбор цвета в слое системы (обязателен; из списка покрытия, а для
колеруемого — любой цвет справочника).
