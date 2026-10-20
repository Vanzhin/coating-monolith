# Авто-номер акта списания (пер-организационный, при создании акта)

> Ревизия (итог): номер присваивается при СОЗДАНИИ черновика (чтобы акт печатался с номером до подписи, затем
> скан+оформление), readonly (подпись не меняет), виден на странице акта и на карточке черновика. Счётчик —
> В ПРЕДЕЛАХ ОРГАНИЗАЦИИ: таблица `compliance_writeoff_act_counter(organization_id → last_number)`, у каждой орг.
> свой ряд 1,2,3…; атомарная выдача `INSERT … ON CONFLICT DO UPDATE … RETURNING`. Старт всегда с 1 на организацию
> (существующие тестовые `act_number` счётчик НЕ сеют). Миграция `Version20261120000000` создаёт таблицу-счётчик.
> (Ранние промежуточные варианты — глобальная SEQUENCE и присвоение при подписи — отменены; детальные секции ниже
> историчны.)

**Goal:** Номер акта списания перестаёт вводиться руками — он присваивается автоматически из сквозного счётчика (как первичный ключ) в момент ПОДПИСАНИЯ акта. Черновик номера не имеет.

**Решения (с автором):**
- Когда: при СОЗДАНИИ черновика (в `AddToWriteOffDraftCommandHandler`, когда открытого черновика ещё нет). Черновик сразу с номером (печатаемый), подпись номер не меняет (readonly).
- Счётчик: сквозной глобальный, одна Postgres SEQUENCE `compliance_writeoff_act_number_seq`. Дыры допустимы (как у PK при откате) — поэтому аллокация ПОСЛЕ прохождения валидаций подписи, чтобы номер не сгорал на частых ошибках (нет скана/причин/комиссии).
- Легаси-номера с прода (миграция кросс-персон) НЕ перенумеровываем: сохраняем оригинальную строку. Старт sequence = max числовых `act_number` + 1, чтобы новые не столкнулись со старыми.

**Архитектура:** `act_number` остаётся nullable `varchar(64)` — хранит либо легаси-строку, либо строковое представление нового целого. Нормальный поток: хендлер подписи берёт `nextActNumber()` у репозитория (он владеет хранилищем акта, включая его sequence) ПОСЛЕ успешного `sign()` и зовёт `assignNumber()` на агрегате. Миграционная консоль проставляет легаси-номер тем же `assignNumber()` напрямую. Поле формы «№ акта» убирается; «№ приказа» (`orderNumber`) остаётся руками — это внешний документ.

## Global Constraints

- Доменные инварианты — в агрегате; хендлер только оркеструет (CLAUDE.md).
- Не плодить сервис-обёртки: счётчик — метод существующего `WriteOffActRepositoryInterface`, не новый `*Allocator`-класс.
- Встроенное вместо кастома: Postgres SEQUENCE, не самодельная таблица-счётчик.
- Миграция идемпотентна (`CREATE SEQUENCE IF NOT EXISTS`, seed только при создании).
- Сообщения/подписи — по-русски, без эмодзи. Один коммит — одна строка.
- Таблица: `compliance_writeoff_act`, колонка `act_number`. Sequence: `compliance_writeoff_act_number_seq`.

## Прод-ops (после мержа)

Схемная миграция создаёт+сеет sequence из уже существующих `act_number`. Порядок на проде (migrate-first ДО свопа): `doctrine:migrations:migrate` — создаст sequence со стартом max+1. Отдельных консольных шагов не требует. ВАЖНО: эта миграция идёт ПОСЛЕ прогона `app:compliance:migrate-writeoff-crossperson` в общей раскатке D5 (иначе sequence сеется из пустой `compliance_writeoff_act` и стартует с 1 — коллизий всё равно нет, т.к. легаси-номера проставляются миграцией кросс-персон ОТДЕЛЬНОЙ строкой, но для чистоты старта лучше migrate-crossperson → затем эта миграция). Если порядок обратный — пересеять: `SELECT setval('compliance_writeoff_act_number_seq', (SELECT COALESCE(MAX(act_number::bigint),0)+1 FROM compliance_writeoff_act WHERE act_number ~ '^[0-9]+$'), false);`.

---

## Task 1: Домен — assignNumber + номер уходит из sign/saveDetails

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/WriteOffAct/WriteOffAct.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/WriteOffAct/WriteOffActTest.php`

**Изменения:**
1. `saveDetails(...)` — убрать первый параметр `string $actNumber` и строку `$this->actNumber = '' === trim($actNumber) ? null : trim($actNumber);`. Остальные реквизиты (дата/комиссия/приказ/представитель) без изменений.
2. `sign(Commission $commission, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now)` — убрать параметр `string $actNumber` и строку `$this->actNumber = trim($actNumber);`. Валидации (assertMutable/assertReasonsComplete/комиссия/скан) и заморозка — без изменений.
3. Новый метод:
```php
/**
 * Закрепить номер акта один раз. Нормальный поток зовёт его ПОСЛЕ успешного sign() со значением из
 * сквозного счётчика ({@see WriteOffActRepositoryInterface::nextActNumber}); миграция кросс-персон — с
 * легаси-строкой. Повторная установка и пустое значение игнорируются (номер неизменяем).
 */
public function assignNumber(string $number): void
{
    if (null !== $this->actNumber || '' === trim($number)) {
        return;
    }
    $this->actNumber = trim($number);
}
```

**Тесты (WriteOffActTest):**
- `sign()` сам по себе номер НЕ ставит (actNumber остаётся null после sign без assignNumber).
- `assignNumber('42')` ставит `'42'`; повторный `assignNumber('99')` не меняет (остаётся `'42'`).
- `assignNumber('')` / `assignNumber('   ')` не ставит (остаётся null).
- `saveDetails(...)` (новая сигнатура) не трогает номер.

## Task 2: Репозиторий — nextActNumber()

**Files:**
- Modify: `app/src/Compliance/Domain/Repository/WriteOffActRepositoryInterface.php`
- Modify: `app/src/Compliance/Infrastructure/Repository/WriteOffActRepository.php`

**Изменения:**
1. В интерфейс:
```php
/** Следующий сквозной номер акта из БД-sequence (потокобезопасно, nextval). Дыры допустимы (как у PK). */
public function nextActNumber(): int;
```
2. В реализацию:
```php
public function nextActNumber(): int
{
    return (int) $this->getEntityManager()->getConnection()
        ->fetchOne("SELECT nextval('compliance_writeoff_act_number_seq')");
}
```

**Тест:** покрывается функционально в Task 6 (подпись → последовательные номера).

## Task 3: Миграция — sequence create+seed

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<timestamp>.php` (сгенерировать `doctrine:migrations:generate`, вписать SQL вручную — diff её не увидит, sequence не в ORM).

**up():** идемпотентно — создать и засеять только при отсутствии:
```sql
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relkind='S' AND relname='compliance_writeoff_act_number_seq') THEN
    CREATE SEQUENCE compliance_writeoff_act_number_seq;
    PERFORM setval(
      'compliance_writeoff_act_number_seq',
      GREATEST(COALESCE((SELECT MAX(act_number::bigint) FROM compliance_writeoff_act WHERE act_number ~ '^[0-9]+$'), 0) + 1, 1),
      false
    );
  END IF;
END $$;
```
**down():** `DROP SEQUENCE IF EXISTS compliance_writeoff_act_number_seq;`

Добавить `$this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', ...)` как в прочих миграциях проекта (свериться с соседней Version*).

## Task 4: Application — команды, хендлеры, контроллер

**Files:**
- Modify: `app/src/Compliance/Application/UseCase/Command/SaveWriteOffAct/SaveWriteOffActCommand.php` — убрать поле `public string $actNumber = ''`.
- Modify: `app/src/Compliance/Application/UseCase/Command/SaveWriteOffAct/SaveWriteOffActCommandHandler.php` — вызов `saveDetails(...)` без первого аргумента (`$command->actNumber` убрать).
- Modify: `app/src/Compliance/Application/UseCase/Command/SignWriteOffAct/SignWriteOffActCommand.php` — убрать поле `public string $actNumber`.
- Modify: `app/src/Compliance/Application/UseCase/Command/SignWriteOffAct/SignWriteOffActCommandHandler.php` — вызов `$act->sign($commission, $actDate, $fileId, $now)` (без actNumber); СРАЗУ после (внутри try, до/после fan-out — до `writeOffActRepository->add($act)`): `$act->assignNumber((string) $this->writeOffActRepository->nextActNumber());`. Репозиторий уже инжектится.
- Modify: `app/src/Compliance/Infrastructure/Controller/WriteOffAct/ShowAction.php` — перестать читать `$input['actNumber']` (строка ~58) и не передавать его в `SaveWriteOffActCommand`/`SignWriteOffActCommand` (строки ~67, ~78).

**Порядок в SignHandler:** `sign()` валидирует и кидает AppException при неготовности ДО аллокации — номер не сгорает. `assignNumber(nextActNumber())` — после успешного `sign()`.

## Task 5: Миграционная консоль — сохранить легаси-номер

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Console/MigrateWriteOffCrossPersonCommand.php`

**Изменения (в методе сборки акта, ~строки 274-294):**
1. `saveDetails(...)` — убрать аргумент номера (новая сигнатура).
2. `sign(...)` — убрать аргумент номера (новая сигнатура): `$act->sign($commission, $actDate ?? $signedAt, $primary['scanFileId'] ?? '', $signedAt);`
3. ПОСЛЕ saveDetails/sign проставить легаси-номер (для черновиков и подписанных — сохраняем как было):
```php
if ('' !== trim((string) ($primary['actNumber'] ?? ''))) {
    $act->assignNumber((string) $primary['actNumber']);
}
```

## Task 6: Форма — убрать поле «№ акта»

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/writeoff/act.html.twig`

**Изменения:** удалить блок `col-md-6` с label «№ акта» и `<input name="actNumber">` (строки ~68-71). «Дата акта» остаётся в том же ряду (col-md-6, полуширина — допустимо). Шапка (строка 26, «Оформлен · № {{ actNumber }}») — без изменений (условие `{% if actNumber %}` само скрывает № у черновика). Фильтр списка по номеру (`index.html.twig`, `ListAction`) — НЕ трогаем. Twig-линт после правки.

## Task 7: Функциональные тесты подписи

**Files:**
- Modify: `app/tests/Functional/Compliance/...` — тесты, шлющие `actNumber` в `SaveWriteOffActCommand`/`SignWriteOffActCommand`, поправить под новые сигнатуры (убрать аргумент). Грепнуть `actNumber` по `tests/Functional/Compliance`.
- Добавить тест (в существующий write-off функциональный класс, напр. `WriteOffFlowTest` или новый `WriteOffActNumberTest`):
  - Подписать два акта подряд (готовые: причины/комиссия/скан) → у первого `actNumber()` = N, у второго = N+1 (последовательные, сквозные).
  - Черновик (saveDetails без подписи) → `actNumber()` === null.
  - Подпись с невалидным состоянием (нет скана) кидает AppException И номер НЕ сгорел: следующий успешный акт получает ожидаемое значение (а не +2). [проверка «аллокация после валидаций»]

## Проверка

- `cd app && yarn dev` (Twig-правка — не требует, но на всякий), `./run check` (style/phpstan/unit/functional) зелёный.
- Ручной смоук: создать акт списания → черновик без номера → оформить (скан+комиссия) → появился № N; следующий акт → N+1.
