# Деплой 1: ИНН контрагента (VO Tin, обязателен для новых, nullable-колонка) + фикс правки заказчика

> Сосед: `docs/plans/counterparty-tin-2.md` (Деплой 2 — флаг `NOT NULL` + полный unique, когда старые ИНН заполнены). Этот файл самодостаточен.

**Цель:** у контрагента появляется ИНН (VO `Tin`, 10/12 цифр + контрольная сумма), уникальный на систему. Обязателен для СОЗДАВАЕМЫХ и РЕДАКТИРУЕМЫХ контрагентов (в т.ч. quick-create из отчёта); существующие записи грузятся с пустым ИНН и требуют его при следующей правке. Колонка nullable, unique — partial (среди заполненных). Заодно чиним баг правки заказчика в форме отчёта. Поиск заказчика (typeahead) — по названию И по ИНН, ИНН виден в подсказке.

**Архитектура:** правило «ИНН валиден» → VO `Tin` (конструктор кидает `AppException`). Правило «ИНН уникален» → доменная спека `UniqueTinCounterpartySpecification` (эталон — `UniqueTitleCounterpartySpecification`), вызывается из `Counterparty::setTin`. Хранение — строковая колонка (VO валидирует на входе, в БД — нормализованные цифры). Application/Infrastructure только пробрасывают.

**quick-create ИНН (решено):** модалка «Новый контрагент» (название предзаполнено + поле ИНН), см. T10.
**Бэкфилл (решено):** контрагентов немного — ИНН существующим проставит пользователь ВРУЧНУЮ в БД. Claude подаёт сигнал после миграции Деплоя 1 (перед Деплоем 2). Скрипт бэкфилла не пишем.

## Global Constraints
- VO — `final readonly`, кидает `App\Shared\Infrastructure\Exception\AppException` (не `InvalidArgumentException`), сообщения на русском для пользователя.
- Бизнес-правила только в домене: валидность ИНН — в `Tin`, уникальность — в спеке из сеттера агрегата. НЕ в контроллере/команде.
- ИНН mandatory на уровне ДОМЕНА (конструктор/сеттер требуют), колонка в БД — **nullable** (Деплой 1), unique — **partial** (`WHERE tin IS NOT NULL`). Строго `NOT NULL` — Деплой 2.
- Тесты зеркалят `src/`. VO — юнит с граничными случаями. Хендлеры — функциональные с реальной БД. Фронт — сборка + браузер, не phpunit.
- Гейты гонять в контейнере (`./run check`), тест-БД мигрировать. Коммиты — по явному апруву.

---

## T0. Фикс бага правки заказчика (независим, тот же виджет)

**Файл:** `app/assets/controllers/reference_select_controller.js`

**Симптом:** при правке отчёта в поле «Заказчик» уже стоит чип; ввод «поверх» ничего не делает (Tagify `mode:'select'` + `maxTags:1` — слот занят, `input`-событие не летит), при сохранении уходит старый id.

**Правка:** ввод поверх существующего выбора должен вести себя как при создании — очищать текущий чип и запускать поиск. Реализация: подписка на ввод/фокус — если есть выбранный тег и пользователь начал печатать, снять тег (`this.tagify.removeAllTags()` / очистить) до/в момент `_onInput`, чтобы `_fetchSuggest` отработал и `_renderHidden` сбросил старый hidden. Очистка `×` уже сбрасывает hidden (проверить). Не ломать первичный префилл (`existingValue` в `connect`).

- [ ] Шаг 1: воспроизвести в браузере (правка отчёта → поле заказчик → печать → сейчас пусто).
- [ ] Шаг 2: правка контроллера (ввод поверх → clear + поиск).
- [ ] Шаг 3: `cd app && yarn dev`, проверить: правка отчёта → меняю заказчика печатанием → подсказка/создать → выбор → сохранение с НОВЫМ id; пустой ввод + сохранение → серверная ошибка «заказчик обязателен».

---

## T1. VO `Tin`

**Файл (новый):** `app/src/Reports/Domain/Aggregate/Counterparty/Tin.php`

`final readonly class Tin implements \JsonSerializable`. Конструктор `__construct(string $value)`: `trim`; валидирует — только цифры, длина 10 или 12, контрольная сумма (алгоритм из эталона `Inn.php`: коэффициенты 10-значного `[2,4,10,3,5,9,4,6,8]`; 12-значного — два разряда `[7,2,4,10,3,5,9,4,6,8]` и `[3,7,2,4,10,3,5,9,4,6,8]`, `%11%10`). Нарушение → `AppException` с человекочитаемым сообщением («ИНН должен состоять из 10 или 12 цифр», «Неверная контрольная сумма ИНН»). Хранит нормализованную строку. `value(): string`, `__toString(): string`, `jsonSerialize(): string`.

- [ ] Тест `app/tests/Unit/Reports/Domain/Aggregate/Counterparty/TinTest.php`: валидный 10-значный, валидный 12-значный, с пробелами (нормализуется), буквы → AppException, длина 9/11/13 → AppException, битая контрольная сумма → AppException. Взять реальные валидные ИНН (напр. 10-знач `7707083893` Сбербанк; 12-знач подобрать проходящий чек-самму).

---

## T2. Агрегат `Counterparty` — поле tin

**Файл:** `app/src/Reports/Domain/Aggregate/Counterparty/Counterparty.php`

- Поле `private ?string $tin = null;` (нормализованные цифры; null только для legacy-строк, гидрируемых Doctrine мимо конструктора).
- Конструктор: добавить параметр `string $tin` ПЕРЕД `?string $description = null` (или после specification, до description) — новый контрагент обязан иметь ИНН. Вызвать `$this->setTin($tin)`.
- `setTin(string $tin): void` — `$vo = new Tin($tin)` (валидирует), `$this->tin = $vo->value()`, затем `$this->specification->uniqueTin->satisfy($this)`.
- `getTin(): ?string`.

Порядок в конструкторе согласовать с вызовами в хендлерах (T6). Сигнатура `__construct(string $id, string $title, CounterpartySpecification $specification, string $tin, ?string $description = null)`.

- [ ] Тест-обновление функциональных хендлеров (T6) покроет; юнит агрегата не заводим отдельно (сеттеры тривиальны поверх Tin/спеки).

---

## T3. Спека уникальности ИНН

**Файлы:**
- Новый `app/src/Reports/Domain/Aggregate/Counterparty/Specification/UniqueTinCounterpartySpecification.php` — зеркало `UniqueTitle...`: `__construct(private CounterpartyRepositoryInterface $repository)`, `satisfy(Counterparty $c)`: если `null !== $c->getTin()` и `($exist = $repository->findOneByTin($c->getTin())) !== null && $exist->getId() !== $c->getId()` → `AppException('Контрагент с ИНН «…» уже существует.')`. Пустой ИНН (legacy) не проверяем.
- `app/src/Reports/Domain/Aggregate/Counterparty/Specification/CounterpartySpecification.php` — добавить `public UniqueTinCounterpartySpecification $uniqueTin` в конструктор группы.

Autowiring спек-группы: проверить, как `CounterpartySpecification` собирается (DI) — добавить новую зависимость (обычно autowire по конструктору).

---

## T4. Репозиторий — findOneByTin + suggest по title/ИНН

**Файлы:**
- `app/src/Reports/Domain/Repository/CounterpartyRepositoryInterface.php` — `public function findOneByTin(string $tin): ?Counterparty;`
- `app/src/Reports/Infrastructure/Repository/CounterpartyRepository.php`:
  - `findOneByTin` → `return $this->findOneBy(['tin' => $tin]);`
  - `suggest(string $query, int $limit)` — расширить WHERE: `LOWER(c.title) LIKE LOWER(:q) OR c.tin LIKE :qraw` (ИНН — цифры, по префиксу). `:qraw` — экранированный `escapeLike`. Порядок `c.title ASC` оставить.

- [ ] Функц. тест репозитория/или через suggest-хендлер: поиск по кусочку названия и по кусочку ИНН находит.

---

## T5. ORM + миграция (nullable + partial unique)

**Файлы:**
- `app/src/Reports/Infrastructure/Database/ORM/Aggregate/Counterparty.Counterparty.orm.xml` — `<field name="tin" type="string" length="12" nullable="true"/>` (unique задаём индексом в миграции, чтобы был PARTIAL — Doctrine `unique="true"` даст полный unique, а нам нужен `WHERE tin IS NOT NULL`; поэтому в XML unique НЕ ставим, только nullable-колонку).
- Новая миграция `app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php` (эталон DDL — `Version20260918130000.php`), идемпотентная:
  - `ALTER TABLE reports_counterparty ADD COLUMN IF NOT EXISTS tin VARCHAR(12) DEFAULT NULL;`
  - `CREATE UNIQUE INDEX IF NOT EXISTS uniq_reports_counterparty_tin ON reports_counterparty (tin) WHERE tin IS NOT NULL;`
  - down: drop index + column.

- [ ] `bin/console doctrine:migrations:migrate -n` в контейнере (dev + test).

---

## T6. Команды/хендлеры — проброс tin

**Файлы:**
- `.../Command/CreateCounterparty/CreateCounterpartyCommand.php` — добавить `public string $tin` (после title). Порядок: `(string $title, string $tin, ?string $description = null)`.
- `.../CreateCounterpartyCommandHandler.php` — `new Counterparty(UuidService::generate(), $command->title, $this->specification, $command->tin, $command->description)`.
- `.../Command/UpdateCounterparty/UpdateCounterpartyCommand.php` — добавить `public string $tin`.
- `.../UpdateCounterpartyCommandHandler.php` — после `setTitle`: `$counterparty->setTin($command->tin);`.
- Result-классы — по желанию добавить `tin` (не обязательно; quick-create JSON вернёт из DTO/Result — см. T8).

- [ ] Функц. тесты хендлеров (реальная БД, трейт `authenticateAsSystem`): create с валидным ИНН → сохранён; create с дублем ИНН → AppException; create с битым ИНН → AppException; update меняет ИНН; update на дубль → AppException.

---

## T7. DTO + Transformer

**Файлы:**
- `app/src/Reports/Application/DTO/Counterparties/CounterpartyDTO.php` — `public ?string $tin = null;`
- `app/src/Reports/Application/DTO/Counterparties/CounterpartyDTOTransformer.php` — `fromEntity`: `$dto->tin = $counterparty->getTin();`

---

## T8. Контроллеры — приём tin + выдача в suggest

**Файлы:**
- `AddAction.php` — читать `tin` из payload → в `CreateCounterpartyCommand`.
- `UpdateAction.php` — POST: `tin` из payload → команда; GET: положить `'tin' => $result->counterparty->tin` в `$inputData`.
- `QuickCreateAction.php` — читать `tin` из JSON body → в `CreateCounterpartyCommand`; в ответ добавить `'tin' => $result->...` (расширить Result или взять из созданного). JSON: `{id, title, tin}`.
- `SuggestCounterpartiesAction.php` — item-map добавить `'tin' => $dto->tin`.

---

## T9. Админ-форма контрагента

**Файл:** `app/src/Shared/Infrastructure/Templates/admin/reports/counterparty/form.html.twig`
- Добавить `input name="tin"` в `#sec-main`: label «ИНН», required, `inputmode="numeric"`, `maxlength="12"`, `pattern="\d{10}|\d{12}"`, value `{{ inputData.tin|default('') }}`. Разметку копировать 1-в-1 с полем `title` (единообразие, без новых классов).

---

## T10. quick-create с ИНН + ИНН в подсказке (виджет reference-select)

**Файл:** `app/assets/controllers/reference_select_controller.js` (+ шаблон формы отчёта при необходимости).

**Подсказка:** в `_fetchSuggest` пробрасывать `tin` из ответа и показывать его в строке дропдауна (`mappedValue`: `«Название» · ИНН 7707…`), поиск сервером уже по обоим (T4).

**Создание с ИНН — МОДАЛКА (решено, вариант A):** при выборе «+ Создать «X»» открывается модалка «Новый контрагент» с предзаполненным названием + обязательное поле ИНН → POST `{title, tin}` на `createUrl`. На 201 — чип получает `{id, title}`, hidden заполняется. На 422 — показать сообщение сервера в модалке (дубль ИНН / битый ИНН), не закрывать. Разметку модалки — по образцу существующих модалок проекта (напр. `components/delete_modal.html.twig`), без новых классов; HTML не дублировать между Twig и JS (использовать `<template>` или отдельный partial, подключаемый один раз в форме отчёта). Только для полей заказчик/подрядчик (контрагент); виджет проекта ИНН не трогает.

- [ ] После правок JS/Twig: `cd app && yarn dev` + браузерный смоук (создать контрагента с ИНН из формы отчёта, дубль ИНН → сообщение, поиск по ИНН находит).

---

## Финал
- [ ] `./run check` зелёный (unit + functional в контейнере), миграция тест-БД накатана.
- [ ] Пересобрать ассеты (`yarn dev`), браузерный смоук: правка заказчика (T0), создание с ИНН, поиск по ИНН.
- [ ] Коммиты партиями (домен+VO / инфра+миграция / формы+JS) — по апруву.
- [ ] Деплой: накатить миграцию. После — СИГНАЛ пользователю: проставить ИНН существующим контрагентам вручную в БД (немного записей). Это предусловие Деплоя 2.
