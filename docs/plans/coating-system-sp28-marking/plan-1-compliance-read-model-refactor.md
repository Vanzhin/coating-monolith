# СП 28 маркировка — План 1: read-model маркировок + доменный сервис-оценщик (рефактор ISO)

Первый из двух деплоев задачи «авто-маркировка систем покрытий по СП 28.13330.2017
по образцу ISO 12944 / ГОСТ 34667.5». Второй — `plan-2-sp28-and-ui.md` (сам `Sp28Evaluator`,
enum'ы среды/условий, данные Ц.1 + маппинг Ц.7, UI-точки). Этот план **не добавляет ни одного
пользовательского изменения** — это чистый рефактор, готовящий почву: маркировка ISO продолжает
работать байт-в-байт, но перекладывается на новую раскладку.

## Зачем (итог обсуждения)

Сейчас соответствие стандартам устроено «не по DDD»:

- Правила и матчинг ISO лежат **в домене агрегата** (`Domain/Aggregate/CoatingSystem/ComplianceEvaluator`,
  `ComplianceRuleBook`), а сам агрегат тащит метод `complianceMatches(ComplianceEvaluator)` — то есть
  `CoatingSystem` знает про стандарты.
- Показ (`CoatingSystemDTOTransformer`) считает соответствие **на лету** через инжектнутый эвалюатор.

Целимся в раскладку, где:

1. **Write-модель `CoatingSystem` чистая** — не знает про стандарты, не хранит маркировок, не имеет
   метода `complianceMatches`. Только состав и физические факты.
2. **Знание о стандартах — в отдельном доменном сервисе** `SystemComplianceEvaluator` с патронажем
   хендлеров `StandardEvaluator` (по одному на стандарт). Он принимает систему и отдаёт `Compliance[]`.
   Подключить стандарт = добавить хендлер (DI-тег), фасад не трогается.
3. **Результат — read-model (проекция-снапшот)** `coating_system_compliance`, которую строит проектор
   по событию `CoatingSystemMutated`. Пассивный «живой справочник»: ничего не делает, просто отдаёт
   «чему система соответствует». Читают и показ (карточки/DTO), и поиск (фасеты).

Это разрешает две боли: агрегат не пухнет производными полями (следующее обогащение = своя read-model,
а не новое поле), и снапшот освежается на каждое изменение системы. По CQRS: read-сторона читает
read-model и **не** зовёт доменные сервисы; write-сторона (проектор при мутации) зовёт оценщик.

Замечание про CLAUDE.md: пример в критерии «домен vs инфраструктура» («список удовлетворяемых
стандартов — поле агрегата, пересчитывается в postMutate») для этого кейса проигрывает read-модели
по расширяемости; идём осознанно read-моделью, пример в доке уточним отдельно.

## Что НЕ меняется этим планом

- Схема таблицы `coating_system_compliance` (`system_id, standard, category, durability`) — **без миграции**.
- Механизм проектора: `CoatingSystemMutated`/`CoatingMutated` → `PublishDomainEventsOnFlushListener`
  → синхронный `event.bus` → хендлер-проектор. Остаётся.
- Поисковый `CoatingSystemFinder` и его `EXISTS`-запрос по `coating_system_compliance`. Не трогаем.
- Фасетный фильтр, `CoatingSystemsFilter`, `CoatingSystemListRequestMapper`, `CoatingSystemListViewFactory`,
  enum'ы `ComplianceStandard`/`IsoCorrosivityCategory`/`IsoDurability` — их использует поиск, оставляем
  на месте (относокировка ISO-нутра в аккуратный неймспейс — косметика, отложена в план 2/позже).
- Twig-шаблоны карточек/модалки и JS. Формат бейджа `category` (+опц. `durability`) сохраняется, потому
  что `ComplianceMatchDTO` остаётся с полями `standard/category/durability`.
- Выдача ISO-маркировок для любой системы — идентична текущей (гарантируется существующими тестами).

## Целевая структура

```
Coatings/Domain/Compliance/                 (новый доменный сервис-слой)
  Compliance.php                               VO результата: (standard, primary, ?secondary)
  StandardEvaluator.php                     interface evaluate(CoatingSystem): Compliance[]  (+ DI-тег)
  SystemComplianceEvaluator.php             патронаж: iterable<StandardEvaluator> → Compliance[]
  Iso12944Evaluator.php                     implements StandardEvaluator; оборачивает ISO-нутро

Coatings/Domain/Aggregate/CoatingSystem/    (ISO-нутро пока остаётся тут, его юзает Iso12944Evaluator)
  ComplianceRuleBook, ComplianceRule, ComplianceMatch, ComplianceMatches,
  PrimerType, ComplianceStandard, Iso12944/IsoCorrosivityCategory, Iso12944/IsoDurability   — ОСТАЮТСЯ
  CoatingSystem.php                         — убираем метод complianceMatches()
  ComplianceEvaluator.php                   — УДАЛЯЕТСЯ (логика уезжает в Iso12944Evaluator)
```

## Секция 1. Доменный сервис-оценщик (`Domain/Compliance/`)

**`Compliance`** — `final readonly`, унифицированная единица результата (одно «соответствие»
системы стандарту), ложится 1-в-1 в строку проекции. `standard` — **enum** `ComplianceStandard`
(не строка), стандарт известен заранее:
```php
public function __construct(
    public ComplianceStandard $standard,  // ISO_12944 | (план 2) SP_28
    public string $primary,               // ISO: category (C3); СП: среда/степень
    public ?string $secondary,            // ISO: durability (H); СП: тип/условия
) {}
```
Плюс `toArray()`/`fromArray()` для проекции (пишем `standard->value`, читаем `ComplianceStandard::from`).
Для ISO `secondary` всегда задан. Имя `Compliance` вместо неудачного `Compliance`; если предпочтёшь
`Conformance`/`StandardCompliance` — тривиально сменить.

**`StandardEvaluator`** — интерфейс. Каждый оценщик знает свой стандарт и умеет сказать, поддерживает
ли он данный:
```php
interface StandardEvaluator
{
    public function supports(ComplianceStandard $standard): bool;

    /** @return Compliance[] чему система соответствует по этому стандарту */
    public function evaluate(CoatingSystem $system): array;
}
```
`supports()` нужен, чтобы (а) роутить/отбирать оценщик по стандарту и (б) в плане 2 строить
self-describing фасеты фильтра (перебор `ComplianceStandard::cases()` × `supports()`). Помечается
DI-тегом (autoconfigure), чтобы патронаж собирался tagged-iterator'ом.

**`SystemComplianceEvaluator`** — фасад-патронаж, DI-сервис:
```php
public function __construct(
    /** @var iterable<StandardEvaluator> */ private iterable $evaluators,
) {}

/** @return Compliance[] всё, чему система соответствует, по всем стандартам */
public function evaluate(CoatingSystem $system): array
{
    $out = [];
    foreach ($this->evaluators as $e) {
        $out = [...$out, ...$e->evaluate($system)];
    }
    return $out;
}
```
Зовётся только write-стороной (проектором). Агрегат его не знает. Подключить стандарт (план 2) =
новый класс с тем же тегом.

**`Iso12944Evaluator implements StandardEvaluator`** — переносит тело нынешнего
`ComplianceEvaluator::evaluate()` (факты системы → фильтрация правил `ComplianceRuleBook::rules()` →
свёртка `ComplianceMatches::strongestOnly()`), в конце маппит каждый `ComplianceMatch` в
`Compliance($m->standard->value, $m->category, $m->durability)`. Правила/enum'ы/свёртку берёт из
существующего ISO-нутра (`Aggregate/CoatingSystem/*`). Рулбук — статические доменные данные (как сейчас).

**Удаляется**: `Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php`,
`Infrastructure/Factory/ComplianceEvaluatorFactory.php` (патронаж теперь через DI-тег, фабрика не нужна).

## Секция 2. Агрегат `CoatingSystem`

- Удаляем метод `complianceMatches(ComplianceEvaluator $evaluator): ComplianceMatches` (строки ~250).
- Оставляем без изменений факт-аксессоры, которые читает `Iso12944Evaluator`: `getSubstrate()`,
  `firstLayer()`, `followupLayers()`, `totalDft()`, `layerCount()`, слои и их `getCoating()->getBase()/isZincRich()`.
- Больше в агрегате ничего про соответствие не остаётся — он про стандарты не знает.

## Секция 3. Write-сторона (проектор + запись снапшота)

- **`CoatingSystemComplianceCacheRepository`**: сигнатуру `rewrite(CoatingSystem, ComplianceEvaluator)`
  меняем на `rewrite(string $systemId, Compliance[] $markings)` — `DELETE ... WHERE system_id` + `INSERT`
  по строке на `Compliance` (`standard, category=primary, durability=secondary`). Репозиторий больше не
  знает про эвалюатор — получает готовые `Compliance[]`. `delete()` без изменений.
- **`RefreshCacheOnCoatingSystemMutatedHandler`**: инжект `ComplianceEvaluator` → `SystemComplianceEvaluator`.
  В `__invoke`: `$markings = $this->evaluator->evaluate($system); $this->complianceCache->rewrite($event->systemId, $markings);`
  (`searchCache->upsert` — как было).
- **`RefreshCacheOnCoatingMutatedHandler`**: та же замена зависимости; для каждой затронутой системы
  (`findByLayerCoatingId`) пересчёт через `SystemComplianceEvaluator` и `rewrite`.

## Секция 4. Read-сторона (показ)

**Что такое read-model здесь (не DTO!).** Read-model — это не класс, а **денормализованный
снапшот в таблице `coating_system_compliance`** (строки `system_id, standard, category, durability`),
который поддерживает проектор. Doctrine-сущностью она не мапится. Цепочка типов:

```
таблица coating_system_compliance   ← персистентный снапшот (сам read-model)
   ↓ read-репозиторий (raw DBAL SELECT)
Compliance[]                        ← доменные VO, гидрированные из строк
   ↓ query-хендлер / трансформер
ComplianceMatchDTO[] в CoatingSystemDTO.compliance   ← presentation-DTO для Twig
```

То есть три разные вещи: **снапшот** (данные в таблице) → **VO `Compliance`** (что вернул read-репозиторий)
→ **DTO** (`ComplianceMatchDTO`, форма для шаблона). «Read-model» = первое; DTO собирается из него уже под показ.

Поток целиком:
- *запись*: система изменилась → `CoatingSystemMutated` → проектор: `SystemComplianceEvaluator.evaluate(system)` → `Compliance[]` → `rewrite(systemId, Compliance[])` → строки снапшота;
- *показ*: query списка → страница систем → read-репо `findBySystemIds(ids)` → `Compliance[]` на систему → `ComplianceMatchDTO` → `CoatingSystemDTO.compliance` → бейджи в Twig;
- *поиск*: finder `EXISTS` по той же таблице (без изменений).

Цель: показ читает снапшот, а не считает на лету. Без N+1 на списке.

- **Read-метод на репозитории проекции** (в `CoatingSystemComplianceCacheRepository` или тонком
  read-репозитории): `findBySystemIds(string[] $ids): array<string, Compliance[]>` (батч на страницу списка)
  и `findBySystem(string $id): Compliance[]` (одна система — модалка/предпросмотр).
- **`CoatingSystemDTOTransformer`**: убираем зависимость от `ComplianceEvaluator` и вызов
  `system->complianceMatches(...)`. Маркировки прокидываются в трансформер снаружи (из query-хендлера,
  который сделал батч `findBySystemIds` для id страницы). `Compliance` → `ComplianceMatchDTO(standard, primary, secondary)`
  — DTO и его поля `standard/category/durability` **не переименовываем**, чтобы Twig/JS не трогать
  (`primary→category`, `secondary→durability`).
- **Query-хендлер списка систем**: после выборки страницы собирает id, зовёт `findBySystemIds`, отдаёт
  карту в трансформер. Предпросмотр одной системы — `findBySystem`.
- Итог: `CoatingSystemDTO.compliance` наполняется из read-model; карточки/модалка визуально идентичны.

## Секция 5. Тесты

- **Новый unit** `Domain/Compliance/SystemComplianceEvaluatorTest` — патронаж из фейкового
  `StandardEvaluator` возвращает объединение; пустой патронаж → пусто.
- **Новый unit** `Domain/Compliance/Iso12944EvaluatorTest` — переносит кейсы из нынешнего
  `ComplianceEvaluatorTest` (match/no-match по каждому критерию + свёртка), но проверяет выдачу `Compliance[]`.
  Старый `ComplianceEvaluatorTest` удаляем вместе с классом.
- **`ComplianceRuleBookTest`, `ComplianceMatchTest`, `ComplianceMatchesTest`, `Iso12944/*Test`,
  `EnumTitlesTest`** — остаются (ISO-нутро не тронуто).
- **Functional `CoatingSystemComplianceCacheRepositoryTest`** — адаптировать под новую сигнатуру
  `rewrite(systemId, Compliance[])`.
- **Functional `CoatingSystemFinderTest`** — не меняется (таблица и запросы те же); прогнать как регресс.
- **`CoatingSystemDTOTransformerTest`** — переписать: трансформер теперь получает `Compliance[]` извне,
  не считает через эвалюатор.

## Секция 6. Бэкфилл

- Схема не меняется, данные в `coating_system_compliance` остаются валидными (тот же shape). Тем не менее
  прогоняем `app:coating-system:rebuild-search-cache` после деплоя, чтобы снапшот пересобрался уже новым
  сервисом (идемпотентно, выдача идентична). Команду `RebuildCoatingSystemSearchCacheCommand` перевести на
  `SystemComplianceEvaluator` + новую `rewrite`.

## Файлы

**Создать**
- `app/src/Coatings/Domain/Compliance/Compliance.php`
- `app/src/Coatings/Domain/Compliance/StandardEvaluator.php`
- `app/src/Coatings/Domain/Compliance/SystemComplianceEvaluator.php`
- `app/src/Coatings/Domain/Compliance/Iso12944Evaluator.php`
- `app/tests/Unit/Coatings/Domain/Compliance/SystemComplianceEvaluatorTest.php`
- `app/tests/Unit/Coatings/Domain/Compliance/Iso12944EvaluatorTest.php`

**Изменить**
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — убрать `complianceMatches`.
- `app/src/Coatings/Infrastructure/Cache/CoatingSystemComplianceCacheRepository.php` — `rewrite(systemId, Compliance[])` + read-методы.
- `app/src/Coatings/Application/Event/RefreshCacheOnCoatingSystemMutatedHandler.php` — на `SystemComplianceEvaluator`.
- `app/src/Coatings/Application/Event/RefreshCacheOnCoatingMutatedHandler.php` — на `SystemComplianceEvaluator`.
- `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` — читать снапшот, без эвалюатора.
- Query-хендлер списка систем (+ предпросмотр) — батч `findBySystemIds` / `findBySystem`.
- `app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemSearchCacheCommand.php` — на новый сервис.
- `app/config/services.yaml` (или атрибут `#[AutoconfigureTag]`) — тег `StandardEvaluator`, tagged-iterator в `SystemComplianceEvaluator`; снять регистрацию `ComplianceEvaluatorFactory`.
- Тесты из Секции 5.

**Удалить**
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php`
- `app/src/Coatings/Infrastructure/Factory/ComplianceEvaluatorFactory.php`
- `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluatorTest.php`

## Проверка

- `vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings` — зелёно.
- Ручной регресс: карточка/модалка системы показывают те же ISO-бейджи; фасетный фильтр по стандарту/
  категории/долговечности работает как прежде (данные из той же таблицы).
- Grep: не осталось ссылок на `ComplianceEvaluator`/`complianceMatches`/`ComplianceEvaluatorFactory`.

## Развилки / риски

- **Батч read на списке.** Показ переходит с live-расчёта на чтение снапшота — добавляется один
  батч-запрос `findBySystemIds` на страницу. Это осознанный CQRS-выбор (read-сторона читает read-model).
  Альтернатива — оставить live-расчёт через сервис по загруженному агрегату; но тогда read-сторона зовёт
  доменный сервис, что мы и уходим. Берём батч-чтение.
- **`Compliance.secondary` nullable.** Для ISO всегда задан; для СП (план 2) — «тип/условия», тоже задан.
  Держим `?string` на будущее (одноосевой стандарт), но проекция пишет пустую строку, а не NULL, если
  когда-то появится одноосевой стандарт — уточним в плане 2.
- **Относокировка ISO-нутра** (`ComplianceRuleBook` и enum'ы из `Aggregate/CoatingSystem/` в `Domain/Compliance/Iso12944/`)
  — косметика, тянет за собой правки в поиске; в этот план не берём, чтобы не смешивать с рефактором логики.

## Не входит (план 2)

`Sp28Evaluator` + enum'ы среды (пять степеней) и условий (три), данные Ц.1, маппинг Ц.7 (`CoatingBase`
финиша → группа), UI-точки: префикс/группировка бейджей по стандарту, ветвление каскадного фильтра по
стандарту (self-describing фасеты). См. `plan-2-sp28-and-ui.md`.
