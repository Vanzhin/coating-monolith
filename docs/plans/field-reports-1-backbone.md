# Field Reports — Деплой 1: костяк отчёта (сервер, онлайн)

Часть серии «Полевые отчёты». **Черновик спеки** (design draft) — реализацию НЕ начинать,
пока не сняты развилки §10 и нет апрува. Дерево может быть занято другим агентом.

Серия: Д0 офлайн-каталог покрытий (спека+impl-план готовы, независим) → **Д1 (этот файл)** →
Д2 фото-пайплайн → Д3 офлайн-first клиент+синк → Д4 пользователь собирает блоки. Общая модель и
контекст — в памяти `project_field_reports_feature`.

---

## 1. Цель

Рабочая **онлайн** фича: авторизованный пользователь заводит отчёт, выбирает тип, выбирает систему
покрытия (пред-заполняет план), заполняет фактические данные по блокам, получает **живые подсказки**
по отклонениям, выбирает шаблон файла и скачивает документ. Форма работает **без JS** (серверная
валидация — источник истины); JS — прогрессивное улучшение (пред-заполнение, живые подсказки).

Отчёт — **корень нового домена и источник истины**. Шаблон файла — одно из представлений (движок
`TemplateRenderer` уже готов, см. `project_template_renderer_engine`).

## 2. Scope

**В Д1:**
- Домен: абстракция **Блока** (code-defined), агрегат **Report**, **типы отчёта** (композиции
  блоков), снимок ссылки на **CoatingSystem** (план), список слоёв «факт», доменное правило точки
  росы (`DewPointCalculator`), generic-проектор `Report → RenderData` + override.
- Application: команды create/update/delete, owner-based `ReportAccessControl`, запросы get/list,
  запрос «оценить подсказки» (as-built vs пороги), проектор для рендера, запрос каталога систем
  (для пикера; офлайн-кеш — в Д3).
- Infrastructure: ORM-персист (JSON-контент + снимок системы + owner), тонкие per-action
  контроллеры (create/edit/list/show/generate/hints/systems-suggest), серверные формы по блокам
  (работают без JS) + прогрессивный JS (пред-заполнение из системы, живые подсказки), рендер через
  `TemplateRendering`, миграция.

**НЕ в Д1 (по деплоям):**
- Съёмка/загрузка/хранение фото и подпись пальцем → **Д2**. Блоки объявляют photo-слоты в схеме,
  но ввод фото инертен; в документе слоты — опциональные `{{x?}}`, пустые.
- Клиентские черновики в IndexedDB, синк, офлайн-мирор подсказок, офлайн-кеш каталога систем → **Д3**.
- Произвольная сборка блоков пользователем → **Д4** (в Д1 только предустановленные типы).

## 3. Доменная модель

### 3.1 Блок и поля (code-defined)
- **Field** — описание свойства: `key`, `type` (enum типов), `label`, `required`, опц. `unit`,
  опц. `options` (для enum), опц. `standard` (ссылка на норму). Типы полей (из разбора актов):
  `text`, `textarea`, `number{unit}`, `date`, `timerange`, `bool`, `enum`(+standard),
  `coatingRef`, `colorRef`, `layers`, `list⟨fields⟩`, `photoSlot` (инертен до Д2).
- **Block** — code-defined единица: `key`, `title`, упорядоченный список `Field`. Регистрируется
  как сервис (tagged-iterator, как драйверы движка) → доменный реестр блоков по `key`.
- Блок сам валидирует свой срез значений (required, диапазоны, enum-опции) → `AppException`
  (человекочитаемо, 422). Блок сам проецирует свой срез в плоский `RenderData` (generic по
  соглашению `blockKey.fieldKey`) + опц. override-метод форматирования.

### 3.2 Тип отчёта (композиция)
- **ReportType** — code-defined: `key`, `title`, упорядоченный `list<blockKey>`. Реестр типов.
- Первые типы: `paint_sample` (Акт выкрасов: A+B+C+D+G+K+L+M), `trial_application`
  (Акт опытного нанесения: A+B+D+E+F+G+H+I+J+K+L+M).

### 3.3 Агрегат Report
- Поля: `id` (передаётся, не генерится внутри — [[feedback_id_passed_not_generated]]), `ownerId`,
  `typeKey`, `status` (`draft`/`completed`), `createdAt`/`updatedAt`, `@Version`
  (оптимистичная блокировка — пригодится для синка Д3), **`systemSnapshot`** (nullable — план),
  **`content`** (значения по блокам), фото-рефы (Д2, пока пусто).
- `content` — структурированный VO (`ReportContent`): map `blockKey → (fieldKey → value)`,
  где значения слоёв (блок G) — список строк `{plan, actual}`. Хранится как JSON (DBAL `JsonType`,
  как в движке/аудите). Валидируется против схемы типа при мутации.
- Инварианты: `typeKey` из реестра; `content` соответствует блокам типа; владелец задан;
  `isOwnedBy(userId)` — доменный предикат владения.
- Мутация через методы агрегата (`fill(blockKey, values)` / `replaceLayers(...)` /
  `attachSystemSnapshot(...)`), не сеттеры-в-лоб. **Список слоёв — вариадик/VO, не сырой array**
  ([[feedback_string_collection]]-дух).

### 3.4 Снимок системы (план) — `CoatingSystemSnapshot` VO
- Захватывается при выборе системы: `{systemId?, title, environment, surfaceTreatment{code,
  standard, title}, layers:[{coatingId?, coatingTitle, colorLabel, dft, dftMin, dftMax}], totalDft}`.
- `final readonly`, `fromArray`/`JsonSerializable` (хранится JSON'ом). Историчность: система в
  каталоге может меняться/замораживаться — снимок не мутирует (как compliance-снимки).
- Засевает строки слоёв «факт» и число слоёв → выбор шаблона (1..4). As-built редактируем и может
  расходиться (доп. слой на локальных участках) — снимок остаётся планом-эталоном.

### 3.5 Правило точки росы — `Coatings/Domain/Service/DewPointCalculator`
- Стиль `FilmThicknessCalculator`. `const DEFAULT_MARGIN_C = 3.0` (ISO 8502-4);
  `minSurfaceTemperature(float $dew, float $margin = self::DEFAULT_MARGIN_C): float`;
  `isSurfaceAcceptable(float $surface, float $dew, float $margin = …): bool`; бонус
  `dewPoint(float $airTemp, Percent $humidity): float` (Магнус — даёт и `/tools`-калькулятор).
  t = `float` °C (могут быть <0), влажность = `Percent`.

### 3.6 Подсказки (домен считает, фронт показывает; БЕЗ блока «Отклонения»)
- Доменный сервис оценки as-built vs пороги, пороги — из снимка системы и существующего домена:
  ТСП vs `dftMin/dftMax`; t vs `applicationMinTemp` (покрытие); даты слоёв vs
  `RecoatingIntervalTree`; t поверхности vs точка росы (`DewPointCalculator`).
- Возвращает список **мягких** сообщений (severity=warning, не блокируют сохранение). Материализо-
  ванного блока сравнения нет; отклонение попадает в документ только через ВИК/примечание/дефекты.

### 3.7 Generic-проектор `Report → RenderData`
- Проходит блоки типа, каждый блок отдаёт свой срез в `RenderData` по соглашению; override на
  тип/поле для форматирования (стыкуется с audit-форматтерами). Новый тип = только композиция.
- «Чего не хватает для этого шаблона» = встроенный `validate()` движка (missing/skipped/unused).

## 4. Application

- **Commands** (`{context}/Application/UseCase/Command`): `CreateReport` (typeKey + опц. systemId →
  снимок+засев слоёв), `UpdateReportBlock`/`UpdateReport` (сохранить значения/слои), `DeleteReport`.
  Хендлеры `implements CommandHandlerInterface` ([[project_command_handler_interface]]); никаких
  бизнес-`if` — правила в домене, ловим `AppException`.
- **Авторизация** — `ReportAccessControl` (Application): `canManage()` (создание) и
  `canEdit(Report)` = `isManager() || $report->isOwnedBy(currentUser)` (передаём загруженный объект,
  не id). Отступление от «мутация только ROLE_ADMIN» — owner-based (см. `project_field_reports_feature`).
- **Queries**: `GetReport` (+ подсказки в проекции или отдельным `EvaluateReportHints`),
  `ListReports` (свои + все у админа), `SuggestCoatingSystems` (для пикера; уже есть
  `SearchCoatingSystemsForSuggest` — переиспользовать).
- **Проектор** для рендера — Application-сервис поверх доменного generic-проектора.

## 5. Infrastructure

- **Персист**: ORM XML на `Report`; `content` и `systemSnapshot` — JSON-колонки через DBAL `JsonType`
  (VO с `JsonSerializable`/`fromArray`). `ownerId` — колонка/FK. Индексы: owner, typeKey, updatedAt.
- **Контроллеры** — per-action, тонкие ([[feedback_controllers_per_action.md]],
  [[feedback_thin_controller.md]]): `Add/Update/List/Show/Generate/Hints/SystemsSuggest`. Ловят
  `\Exception` → рендер ошибки; `Generate` отдаёт файл (reuse `TemplateRendering`).
- **Формы** — серверные, по блокам, работают без JS (валидация на бэке). JS прогрессивно: пик
  системы пред-заполняет; живые подсказки дергают `Hints`-эндпоинт (онлайн; офлайн-мирор — Д3).
  Стили копируем у ближайшего аналога cabinet ([[feedback_reuse_styles.md]]), состояние — по месту.
- **Рендер**: выбор шаблона файла → `validate()` (показать missing) → `render()` → скачать.
- **Миграция** идемпотентная (таблица `report`).

## 6. UI-поток

1. «Новый отчёт» → выбор типа.
2. Выбор системы (typeahead) → снимок-план, засев блоков D/E/G.
3. Заполнение блоков по порядку; в блоке «Нанесение» — строки слоёв (номинал показан, факт вводим),
   под полями — живые подсказки.
4. Блоки H/I/J/K/M — по типу. Фото-слоты видны, но ввод — заглушка до Д2.
5. «Сформировать» → выбор шаблона → (если чего-то не хватает — список) → скачать документ.

## 7. Тесты

- Домен (unit, зеркалят src): конструкторы/валидация полей и блоков (граничные → `AppException`);
  `ReportContent` round-trip; `CoatingSystemSnapshot` from/to array; `DewPointCalculator` (порог,
  граница ±, Магнус); generic-проектор (блоки → ожидаемый `RenderData`); подсказки (as-built vs пороги).
- Application (functional, реальная БД, `authenticateAsSystem`/owner): create с/без системы (засев),
  update, delete; `ReportAccessControl` (владелец правит своё, чужой — Forbidden, админ — всё);
  консистентность схема↔шаблон через `variables()` (приём как в audit config-тесте).
- Infrastructure: контроллеры мутации (логин ROLE_ADMIN/owner), `Generate` отдаёт непустой файл
  и корректный content-type; аноним на `/cabinet` → редирект.
- Фронт — без phpunit ([[feedback_no_phpunit_on_frontend.md]]): сборка + браузерный смоук.

## 8. Файлы (по областям; точный список — в impl-плане)

- `Reports/Domain/`: `Aggregate/Report/{Report, ReportContent, CoatingSystemSnapshot, LayerRow…}`,
  `Block/*` (абстракция+конкретные блоки A–M), `ReportType/*` (реестр), `Repository/*` (интерфейсы),
  `Service/*` (проектор, оценка подсказок).
- `Coatings/Domain/Service/DewPointCalculator.php` (правило точки росы — по указанию, рядом с
  калькуляторами Coatings).
- `Reports/Application/`: `UseCase/Command|Query/*`, `Service/AccessControl/ReportAccessControl`,
  `DTO/*`, проектор-адаптер.
- `Reports/Infrastructure/`: `Controller/*` (per-action), `Database/ORM/*`, `Repository/*`,
  `Mapper/*` (форма↔DTO, чистый shape), `Templates/cabinet/report/*`.
- Миграция в `Shared/Infrastructure/Database/Migrations/Version*.php`.
- Ассеты: контроллеры Stimulus (пред-заполнение из системы, живые подсказки).

> Bounded context — **новый `Reports`** (не в `Coatings`/`Documents`): отчёт — самостоятельный
> корень. Правило точки росы — исключение, живёт в `Coatings` рядом с физикой по указанию пользователя.

## 9. Риски

- Гетерогенный `content` в JSON: гибко, но валидацию против схемы блоков надо держать строго в
  домене; тест консистентности схема↔шаблон обязателен.
- «Число слоёв → шаблон 1..4»: если система с >4 слоями — движок не тянет (repeat нет). Ограничить/
  предупредить (в актах 1–4).
- Объём Д1 большой; при реализации можно разбить внутренне на под-шаги (домен → application →
  рендер → UI), но это один деплой (рабочая онлайн-фича целиком).

## 10. Развилки к решению (с рекомендацией) — снять до impl-плана

1. **enum-поля со стандартом** (степень подготовки, класс ржавления, обеспыливание, шероховатость):
   *Рек.* — `enum` с code-defined набором опций + ссылка на норму + опция «иное» (свободный текст).
2. **Растворитель/абразив**: *Рек.* — на Д1 `text` + № партии (нет отдельного каталога продуктов);
   `productRef` — когда/если появится справочник. Цвет слоя — `colorRef` (переиспользовать
   существующий Color-suggest), берётся из снимка системы.
3. **Подпись комиссии**: *Рек.* — на Д1 блок M без изображения подписи (ФИО/должность/дата/орг —
   текст); рисование пальцем (`signatureSlot`) — в Д2 вместе с медиа.
4. **Жизненный цикл**: *Рек.* — `draft`/`completed`, без жёсткой заморозки (владелец/админ правят);
   сертификация/lock — позже.
5. **Один отчёт ↔ одна система** (акт = одна система на участок): *Рек.* — да, одна на Д1.
6. **Проект/Объект/Заказчик** (блок B): *Рек.* — текстовые поля на Д1 (домена «Проекты» нет).
7. **Ручной ввод слоёв без системы**: *Рек.* — фолбэк оставить (happy-path — от системы), т.к.
   иначе тупик, если системы ещё нет в каталоге.

## 11. Статус

Черновик. Не закоммичен (git ведёт разработчик, [[feedback_no_commits]]). Дальше: снять развилки
§10 → финализировать спеку → writing-plans (impl-план по TDD) → реализация по шагам. Ветка от `main`
(предложено `feat/field-reports-backbone`).

Апдейт: D1 фактически реализован (форма создания-модалкой → заполнение с реквизитами+блоками, генерация .docx, per-count шаблоны, проверка готовности, удаление, подсказки слоя ТСП/точка-роса/цвет, composite-поля Thickness/Thinner). См. [[project_field_reports_feature]].

## 12. Эволюция в «конструктор отчётов» — приёмы из dev-partner-group

Разбор соседнего проекта `dev-partner-group` (Yii2 + Next.js; настраиваемая форма заявки: тип →
банк → продукт → набор полей из БД). Их модель = наш будущий конструктор (тип отчёта → блоки/поля →
шаблон → маппинг, всё как данные). Ниже — что перенять при переводе наших **code-defined** блоков в
**data-driven**. Наш текущий состав (`BlockDefinition`/`Field`/`FieldType`, `ReportType->blockKeys()`,
`ReportContentValidator`, schema-driven виджет, `ReportTemplateMap`) — уже правильный каркас, не хватает
хранения схемы в данных + пары улучшений.

### 12.1 Что взять
1. **Эндпоинт «дай схему формы», ключи схемы = ключи тела сабмита.** `GET report-types/{id}/schema` →
   `{sections, blocks/fields[]}`; по тем же ключам принимаем контент. Фронт перестаёт хардкодить форму.
   (У них `POST /offer-ag/fields` → `{layout, sections[], fields[]}`, фронт рендерит по контракту.)
2. **Богатый декларативный дескриптор поля** (их `OfferAgFieldsField`): к нашему `Field` добавить
   `validators[]`, `options[]`, `autocomplete{url, depends_on, value_field, label_field}` (декларативная
   привязка к справочнику — заменяет захардкоженный `coating_ref`), `show_if/watch_field` (зависимости
   полей), `section`, `mask`. Держать под одной OpenAPI-схемой как единственным контрактом.
3. **Композиция = базовая библиотека полей + overrides/дифф**, а не плоский список ключей. Тип отчёта =
   выбор блоков из общего реестра + точечные `extra_required`/`remove_fields`/`flags`. Эволюция нашего
   `ReportType->blockKeys()`: один блок в разных типах с разной обязательностью/видимостью.
4. **`type→виджет` — статическая мапа с мягким фолбэком на неизвестный тип** (у нас уже так в Twig;
   их `FieldRenderer.tsx` — то же на клиенте: unknown → warn + null, добавление типа не рушит контракт).
5. **Снимок выбранного справочного значения рядом с ключом** (их `add_field_value_id` + копия `value`).
   Мы это делаем для `Reference`/`coating_ref` — распространить на enum/любые справочники (id/код + подпись).
6. **enum: машинный `code` отдельно от подписи `value`** — code в плейсхолдер .docx, value в UI.
7. **Сервер отбрасывает/ругается на поля не из схемы** (их `saveAddFields` перепроверяет принадлежность).
   Наш `ReportContentValidator` должен валидировать не только значения, но и «ключ принадлежит этому типу».
8. **Их конструктор ОТЧЁТОВ — прямой шаблон** (`services/app/Report/FieldRoleMapping.php`:
   `MAPPING[reportType][fieldId] = {roles, group, default_selection, label}` + `LAYOUT.groups`,
   `ReportHandler::validateFieldsExistInDomain()`). Взять модель целиком (роли/группы/дефолт-выбор/
   валидация состава), но **хранить в БД**, не в коде.

### 12.2 Обязательные улучшения, которых у них НЕТ (наши требования)
- **Снимок схемы (version/JSON) в отчёте.** У них схема пересобирается вживую → правка label/required
  ломает воспроизводимость старых заявок. Для «регулируемой под каждый запрос» конфигурации отчёт
  обязан нести снимок схемы, с которой создавался (историчность, как compliance/reference-снимки).
- **Единый бэковый движок валидации декларативных `validators[]`.** У них `validators[]` — только для
  фронт-UX, на сервере legacy-свитчи, единого движка нет. Наш принцип «бэк — источник истины»
  ([[project_field_reports_feature]]): если берём декларативные правила, исполнять их и на бэке (там же,
  где сейчас `ReportContentValidator` + VO Percent/PositiveNumber/CoatingThickness/Thinner).

### 12.3 Что НЕ брать (их антипаттерны)
- **Полу-data-driven:** ядро состава в коде (`RkoFieldConfig::getBankConfigs()` — гигантский PHP-массив),
  в БД только «добавки» → новый банк = код + миграция. Наш конструктор доводить до конца — весь состав данными.
- **Две системы полей-близнецов** (`fields/*` и `add_fields/*`) — не плодить второй реестр.
- **Две колонки типа на поле** (`type` строка + `field_type` int) — у нас один `FieldType`, так и держать.
- **Выходной маппинг классом-на-получатель** (`bankModels/Delo.php` императивно) — мы уходим в data-driven
  проекцию поле→плейсхолдер, не повторять.
- **Приведение типа значения по хардкод-спискам имён** (`RequestFormMapper::castValue`) — тип брать из
  дескриптора (`FieldType`), не из отдельных списков.

### 12.4 Ключевые файлы dev-partner-group (для сверки, `modules/backend/app/`)
- Дескрипторы/композиция: `modules/ApiAppV1/services/Request/{FieldDefinitions,RkoFieldConfig,FieldRegistry,FieldDataLoader,SectionRegistry}.php`
- Контракт: `modules/ApiAppV1/swagger/Schemas/Response/OfferAg/OfferAgFieldsField.php`
- Конструктор отчётов: `services/app/Report/{FieldRoleMapping,ReportHandler}.php`
- EAV: таблицы `add_fields`/`products_add_fields`/`banks_add_fields`/`requests_add_fields`
- Фронт: `modules/frontend/app/src/features/requestForm/{components/FieldRenderer.tsx,hooks/useRequestFields.ts,hooks/useFormBuilder.ts}`
