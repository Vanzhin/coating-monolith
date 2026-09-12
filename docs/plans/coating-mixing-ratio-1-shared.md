# Соотношение компонентов: движок в Shared + атрибут покрытия (Деплой 1)

Часть арки «Инструменты» — см. `docs/plans/tools-mixing-calculator-overview.md`.
Этот деплой — только бэк: доменный движок расчёта долей, атрибут `MixingRatio` на покрытии,
персистентность, юнит/функциональные тесты. Форма редактирования и UI-калькулятор — Деплой 2+.

## Контекст

Многокомпонентные покрытия имеют соотношение смешивания основы с отвердителем/добавками:
объёмное и/или массовое (3:1 по объёму, 100:23 по массе). Нужно доменный класс, который держит
соотношение (не сами компоненты) и умеет считать дозировки, + привязка к агрегату `Coating`.

Движок расчёта долей общий (пригодится публичному калькулятору инструментов, вне Coatings),
поэтому живёт в `Shared`. `MixingRatio` (связка объём+масса как свойство материала +
персистентность) — про покрытие, остаётся в Coatings.

## Развилки (согласовано)

- **Размещение**: `PartsRatio` + `MixDose` → `App\Shared\Domain\Aggregate\ValueObject\`
  (рядом с `PositiveNumberRange` и пр.). `MixingRatio` остаётся в
  `App\Coatings\Domain\Aggregate\Coating\`, меняет только `use` на Shared-`PartsRatio`.
- **Число компонентов**: список из N долей. Однокомпонентное покрытие — соотношения нет
  (`null` на агрегате).
- **База измерения**: обе (объём и масса) в одном VO `MixingRatio`, каждая опциональна.
- **Тип долей**: `float` (100:23, 3.5:1).
- **Расчёты**: `fromComponent(int, float)` — движок; фасады `fromBase`, `fromHardener`,
  `fromTotal`.

## Модель

### `PartsRatio` (Shared, `App\Shared\Domain\Aggregate\ValueObject`)
`final readonly`. Конструктор `float ...$parts`, `list<float>`.
- Инварианты (`AppException`): частей ≥ 2; каждая доля > 0.
- Движок `fromComponent(int $index, float $amount): MixDose` — `$index` в диапазоне,
  `$amount > 0`; `scale = $amount / parts[$index]`; `amounts[i] = parts[i] * scale`.
- Фасады: `fromBase(float)` → `fromComponent(0, …)`; `fromHardener(float)` → только при ровно
  2 компонентах (иначе `AppException`); `fromTotal(float)` → `scale = total / sum(parts)`.
- `getParts(): list<float>`, `count(): int`.

### `MixDose` (Shared, `App\Shared\Domain\Aggregate\ValueObject`)
`final readonly`. `float ...$amounts` (0 = основа). `getAmounts/getBase/getAdditions/getTotal`.
Сырые числа, округление — на UI.

### `MixingRatio` (Coatings, `App\Coatings\Domain\Aggregate\Coating`)
`final readonly implements \JsonSerializable`. Поля `?PartsRatio $byVolume`, `?PartsRatio $byMass`.
- Инварианты: задана хотя бы одна база; если обе — одинаковое число компонентов.
- `jsonSerialize(): {volume: [..]|null, mass: [..]|null}`; `fromArray(array): self`.
- `use App\Shared\Domain\Aggregate\ValueObject\PartsRatio;` (единственное изменение по сути).

## Привязка к `Coating`
Поле `?MixingRatio $mixingRatio = null` + `getMixingRatio()`/`setMixingRatio(?MixingRatio)`
(через сеттер, не в конструкторе — как `dryHeatExposure`). Уже сделано, не трогаем.

## Персистентность
`MixingRatioType extends AbstractJsonObjectType` (Coatings/Infrastructure/Database/DBAL),
регистрация в `doctrine.yaml`, ORM XML nullable-колонка `mixing_ratio` типа `mixing_ratio`,
идемпотентная миграция `ADD COLUMN IF NOT EXISTS mixing_ratio JSONB`. Уже сделано, не трогаем.

## Что делаем в этом деплое (рефактор незакоммиченного кода)

Текущий код `MixingRatio` лежит незакоммиченным на месте старой раскладки. Перекладываем
сразу в целевую, промежуточной фиксации старого layout не делаем.

1. Переместить файл `PartsRatio.php`: `Coatings/Domain/Aggregate/Coating/` →
   `Shared/Domain/Aggregate/ValueObject/`, namespace → `App\Shared\Domain\Aggregate\ValueObject`.
2. Так же `MixDose.php`.
3. В `MixingRatio.php` заменить `use` на Shared-`PartsRatio` (PartsRatio уже импортируется
   из того же неймспейса, что и класс — заменить на новый).
4. Перенести тесты `PartsRatioTest.php`, `MixDoseTest.php`:
   `tests/Unit/Coatings/Domain/Aggregate/Coating/` → `tests/Unit/Shared/Domain/Aggregate/ValueObject/`,
   namespace → `App\Tests\Unit\Shared\Domain\Aggregate\ValueObject`, поправить `use`.
5. `MixingRatioTest.php` остаётся в Coatings, поправить `use` на Shared-`PartsRatio`.
6. `CoatingMixingRatioPersistenceTest.php` (функциональный) остаётся в Coatings, но тоже
   импортирует `PartsRatio` напрямую — поправить `use` на Shared. (Уточнение к первичному
   плану: он не «неизменный».)
7. Проверить прочие ссылки на классы (грепнуть `PartsRatio`, `MixDose` по проекту) — кроме
   `MixingRatio` и тестов их быть не должно.

Не меняются: `Coating.php` (MixingRatio в том же неймспейсе — `use` не нужен), ORM XML,
`doctrine.yaml`, миграция, `MixingRatioType`.

## Тесты
- `tests/Unit/Shared/Domain/Aggregate/ValueObject/PartsRatioTest`: <2 долей; доля ≤ 0;
  fromComponent (валид/вне диапазона/amount ≤ 0); fromBase; fromHardener (2-комп ок, 3-комп →
  AppException); fromTotal (валид/≤ 0); нецелый массовый результат.
- `tests/Unit/Shared/Domain/Aggregate/ValueObject/MixDoseTest`: getBase/getAdditions/getTotal.
- `tests/Unit/Coatings/Domain/Aggregate/Coating/MixingRatioTest`: объём/масса/обе; пустой →
  AppException; рассинхрон числа компонентов → AppException; round-trip fromArray/jsonSerialize.
- `tests/Functional/.../CoatingMixingRatioPersistenceTest`: сохранение/перечитка + null-кейс.

## Верификация
`./run check` (style, phpstan, unit, functional). Functional требует накатанной схемы
`test_db` (миграция `mixing_ratio` в т.ч.) — см. [[reference_test_run_env]].

## Отложено
- Форма редактирования соотношения в карточке покрытия.
- Публичный раздел «Инструменты» + калькулятор (Деплой 2).
- Поиск по покрытиям в калькуляторе (Деплой 3).
