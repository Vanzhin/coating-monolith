# CoatingSystem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Переписать черновик `CoatingSystem` в полноценный агрегат — упорядоченный набор `Coating` с метаданными, инвариантами совместимости и вычисляемым индексом соответствия стандартам (ISO 12944).

**Architecture:** DDD-агрегат внутри существующего bounded context `Coatings/`. Слой = отдельная entity `CoatingSystemLayer` с `position`. Расчёт соответствия — доменный сервис `ComplianceEvaluator` поверх const-справочника `ComplianceRuleBook`. Индекс `coating_system_compliance` заполняется Doctrine listener-ом.

**Tech Stack:** PHP 8.3, Symfony 7.0, Doctrine ORM (XML mapping), PostgreSQL 17, PHPUnit 9.6, Twig, Stimulus.

**Spec:** `docs/plans/coating-system/design.md` — читать перед началом каждой задачи.

## Global Constraints

- Все инварианты — в домене. Handler-ы тонкие, без `if`-ов с бизнес-смыслом. Mapper — только shape ↔ shape, никаких `throw`-ов с бизнес-правилами.
- User-facing ошибки — `App\Shared\Infrastructure\Exception\AppException` (HTTP 422). Сообщения на русском.
- Handler-ы регистрируются через `implements App\Shared\Application\Command\CommandHandlerInterface` — без `#[AsMessageHandler]`.
- Контроллеры — per-action (по образцу `Coatings/Infrastructure/Controller/Coating/{Add,Update,List,View,Remove}Action.php`), не mono-controller-ы.
- ORM mapping — XML в `Coatings/Infrastructure/Database/ORM/Aggregate/`.
- Миграции идемпотентные (`IF NOT EXISTS` / проверки на состояние).
- Никаких эмодзи в коде и сообщениях. Никаких `dd()` / `var_dump` / закомментированного кода.
- Тесты зеркалят структуру `src/`: `tests/Unit/Coatings/...` / `tests/Functional/Coatings/...`.
- В юнит-тестах домена — реальные объекты, без моков. В функциональных — реальная БД (не Doctrine-моки).
- SDD-режим: implementer коммитит после каждой задачи (`git commit` разрешён именно в этом плане, потому что review идёт per-task). Сообщения коммитов на английском, стиль `feat(coatings): ...` / `test(coatings): ...`.
- Ассеты собираются `cd app && yarn dev` только после правок JS/CSS/Twig.
- Тесты запускать через `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem` (или узкий путь). Миграции: `cd app && bin/console doctrine:migrations:migrate -n`.

---

## File Structure

**Domain (Coatings/Domain/Aggregate/CoatingSystem/):**
- `Substrate.php` — enum типа подложки.
- `SurfacePreparation.php` — VO {grade, description, ?standard}.
- `ComplianceStandard.php` — enum ISO_12944.
- `PrimerType.php` — enum ZINC_RICH/OTHER.
- `Iso12944/IsoCorrosivityCategory.php` — enum C1..CX, Im1..Im3.
- `Iso12944/IsoDurability.php` — enum LOW..VERY_HIGH.
- `ComplianceRule.php` — VO одного правила.
- `ComplianceRuleBook.php` — const-справочник правил.
- `ComplianceEvaluator.php` — доменный сервис расчёта.
- `CoatingSystem.php` — корень агрегата (переписан).
- `CoatingSystemLayer.php` — child-entity.
- `CoatingSystemChainValidator.php` — валидатор совместимости соседей.

**Удаляются (устаревшие черновики):**
- `Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemSurface.php`
- `Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemSurfaceTreatment.php`

**Coating изменения:**
- `Coatings/Domain/Aggregate/Coating/Coating.php` — добавить `bool $isZincRich`.
- `Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` — маппинг поля.

**Repository / Infra (Coatings/Domain/Repository/ и Coatings/Infrastructure/):**
- `Domain/Repository/CoatingSystemRepositoryInterface.php`.
- `Domain/Repository/CoatingSystemsFilter.php`.
- `Infrastructure/Repository/CoatingSystemRepository.php`.
- `Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml`.
- `Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystemLayer.orm.xml`.
- `Infrastructure/Database/DBAL/SurfacePreparationType.php` — JSON DBAL type.
- `Infrastructure/Projector/CoatingSystemComplianceProjector.php` — Doctrine listener.
- `Infrastructure/Console/RebuildCoatingSystemComplianceCommand.php`.

**Application (Coatings/Application/):**
- `DTO/CoatingSystems/CoatingSystemDTO.php`, `CoatingSystemLayerDTO.php`, `CoatingSystemDTOTransformer.php`.
- `UseCase/Command/CreateCoatingSystem/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Command/UpdateCoatingSystemMetadata/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Command/RemoveCoatingSystem/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Command/AppendLayer/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Command/InsertLayerAt/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Command/RemoveLayerAt/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Command/MoveLayer/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Command/UpdateLayerDft/{Command,CommandHandler,CommandResult}.php`.
- `UseCase/Query/FindCoatingSystemById/{Query,QueryHandler}.php`.
- `UseCase/Query/ListCoatingSystems/{Query,QueryHandler}.php`.
- `UseCase/Query/SearchCoatingSystemsByCompliance/{Query,QueryHandler}.php`.

**Controllers (Coatings/Infrastructure/Controller/CoatingSystem/):**
- `AddAction.php`, `UpdateAction.php`, `ListAction.php`, `ViewAction.php`, `RemoveAction.php`, `SearchByComplianceAction.php`.

**API (Coatings/Infrastructure/Api/CoatingSystem/):**
- `SearchByComplianceApiAction.php`.

**Mapper:**
- `Coatings/Infrastructure/Mapper/CoatingSystemMapper.php`.

**Twig (app/templates/cabinet/coating/coating_system/):**
- `list.html.twig`, `form.html.twig`, `view.html.twig`, `search_by_compliance.html.twig`.

**Stimulus (app/assets/controllers/):**
- `coating_system_form_controller.js`.

**Migration (Coatings/../Shared/Infrastructure/Database/Migrations/):**
- `Version20260726120000.php` (или следующее свободное).

**Config (app/config/):**
- `doctrine.yaml` — регистрация `SurfacePreparationType`.

**Tests:** зеркалят `src/` в `tests/Unit/Coatings/...` и `tests/Functional/Coatings/...`.

---

## Task 1: Enum-ы номенклатуры

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/Substrate.php`
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceStandard.php`
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/PrimerType.php`
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/Iso12944/IsoCorrosivityCategory.php`
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/Iso12944/IsoDurability.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/EnumTitlesTest.php`
- Delete: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemSurface.php`
- Delete: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemSurfaceTreatment.php`

**Interfaces produced (используются всеми последующими задачами):**
- `Substrate` enum-cases: `STEEL_CARBON`, `STEEL_GALVANIZED`, `STEEL_METALLIZED`, `CONCRETE`, `ALUMINUM`. Методы `title(): string`, `description(): string`.
- `ComplianceStandard` enum-cases: `ISO_12944`. Методы `title()`, `description()`.
- `PrimerType` enum-cases: `ZINC_RICH`, `OTHER`. Методы `title()`, `description()`.
- `IsoCorrosivityCategory` enum-cases: `C1..C5`, `CX`, `IM1..IM3`. Методы `title()`, `description()`.
- `IsoDurability` enum-cases: `LOW`, `MEDIUM`, `HIGH`, `VERY_HIGH`. Методы `title()`, `description()`.

- [ ] **Step 1: Написать проваливающийся тест на title()/description() всех enum-ов**

Файл `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/EnumTitlesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoCorrosivityCategory;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoDurability;
use App\Coatings\Domain\Aggregate\CoatingSystem\PrimerType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use PHPUnit\Framework\TestCase;

final class EnumTitlesTest extends TestCase
{
    public function test_substrate_titles(): void
    {
        self::assertSame('Углеродистая сталь', Substrate::STEEL_CARBON->title());
        self::assertSame('Оцинкованная сталь', Substrate::STEEL_GALVANIZED->title());
        self::assertSame('Бетон', Substrate::CONCRETE->title());
    }

    public function test_compliance_standard_titles(): void
    {
        self::assertSame('ISO 12944', ComplianceStandard::ISO_12944->title());
        self::assertStringContainsString('ГОСТ 34667.5', ComplianceStandard::ISO_12944->description());
    }

    public function test_primer_type_titles(): void
    {
        self::assertSame('Zn(R)', PrimerType::ZINC_RICH->title());
        self::assertSame('Прочие', PrimerType::OTHER->title());
    }

    public function test_iso_corrosivity_titles(): void
    {
        self::assertSame('C1', IsoCorrosivityCategory::C1->title());
        self::assertSame('Очень низкая', IsoCorrosivityCategory::C1->description());
        self::assertSame('C3', IsoCorrosivityCategory::C3->title());
        self::assertSame('Средняя', IsoCorrosivityCategory::C3->description());
        self::assertSame('Im1', IsoCorrosivityCategory::IM1->title());
    }

    public function test_iso_durability_titles(): void
    {
        self::assertSame('L', IsoDurability::LOW->title());
        self::assertStringContainsString('менее 7', IsoDurability::LOW->description());
        self::assertSame('VH', IsoDurability::VERY_HIGH->title());
        self::assertStringContainsString('более 25', IsoDurability::VERY_HIGH->description());
    }
}
```

- [ ] **Step 2: Запустить тест — убедиться, что фейлится (классов нет)**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/EnumTitlesTest.php
```

Ожидание: `Error: Class ... not found`.

- [ ] **Step 3: Создать `Substrate` enum**

Файл `app/src/Coatings/Domain/Aggregate/CoatingSystem/Substrate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

enum Substrate: string
{
    case STEEL_CARBON     = 'steel_carbon';
    case STEEL_GALVANIZED = 'steel_galvanized';
    case STEEL_METALLIZED = 'steel_metallized';
    case CONCRETE         = 'concrete';
    case ALUMINUM         = 'aluminum';

    public function title(): string
    {
        return match ($this) {
            self::STEEL_CARBON     => 'Углеродистая сталь',
            self::STEEL_GALVANIZED => 'Оцинкованная сталь',
            self::STEEL_METALLIZED => 'Сталь с термически напылённым металлом',
            self::CONCRETE         => 'Бетон',
            self::ALUMINUM         => 'Алюминий',
        };
    }

    public function description(): string
    {
        return $this->title();
    }
}
```

- [ ] **Step 4: Создать `ComplianceStandard`, `PrimerType`, `Iso12944/IsoCorrosivityCategory`, `Iso12944/IsoDurability`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceStandard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

enum ComplianceStandard: string
{
    case ISO_12944 = 'ISO_12944';

    public function title(): string
    {
        return match ($this) {
            self::ISO_12944 => 'ISO 12944',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ISO_12944 => 'ISO 12944 (ГОСТ 34667.5—2021)',
        };
    }
}
```

`app/src/Coatings/Domain/Aggregate/CoatingSystem/PrimerType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

enum PrimerType: string
{
    case ZINC_RICH = 'zinc_rich';
    case OTHER     = 'other';

    public function title(): string
    {
        return match ($this) {
            self::ZINC_RICH => 'Zn(R)',
            self::OTHER     => 'Прочие',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ZINC_RICH => 'Цинкнаполненная грунтовка (≥80% цинка по массе)',
            self::OTHER     => 'Прочие типы грунтовок',
        };
    }
}
```

`app/src/Coatings/Domain/Aggregate/CoatingSystem/Iso12944/IsoCorrosivityCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944;

enum IsoCorrosivityCategory: string
{
    case C1  = 'C1';
    case C2  = 'C2';
    case C3  = 'C3';
    case C4  = 'C4';
    case C5  = 'C5';
    case CX  = 'CX';
    case IM1 = 'Im1';
    case IM2 = 'Im2';
    case IM3 = 'Im3';

    public function title(): string
    {
        return $this->value;
    }

    public function description(): string
    {
        return match ($this) {
            self::C1  => 'Очень низкая',
            self::C2  => 'Низкая',
            self::C3  => 'Средняя',
            self::C4  => 'Высокая',
            self::C5  => 'Очень высокая',
            self::CX  => 'Экстремальная',
            self::IM1 => 'Погружение в пресную воду',
            self::IM2 => 'Погружение в морскую или слабоминерализованную воду',
            self::IM3 => 'Погружение в грунт',
        };
    }
}
```

`app/src/Coatings/Domain/Aggregate/CoatingSystem/Iso12944/IsoDurability.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944;

enum IsoDurability: string
{
    case LOW       = 'LOW';
    case MEDIUM    = 'MEDIUM';
    case HIGH      = 'HIGH';
    case VERY_HIGH = 'VERY_HIGH';

    public function title(): string
    {
        return match ($this) {
            self::LOW       => 'L',
            self::MEDIUM    => 'M',
            self::HIGH      => 'H',
            self::VERY_HIGH => 'VH',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LOW       => 'Низкая (менее 7 лет)',
            self::MEDIUM    => 'Средняя (от 7 до 15 лет)',
            self::HIGH      => 'Высокая (от 15 до 25 лет)',
            self::VERY_HIGH => 'Очень высокая (более 25 лет)',
        };
    }
}
```

- [ ] **Step 5: Удалить черновики CoatingSystemSurface и CoatingSystemSurfaceTreatment**

```bash
rm app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemSurface.php
rm app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemSurfaceTreatment.php
```

Проверить, что нигде не используются:

```bash
grep -rn "CoatingSystemSurface\|CoatingSystemSurfaceTreatment" app/src app/tests app/config app/templates
```

Ожидание: пусто (если что-то нашлось — удалить/поправить).

- [ ] **Step 6: Запустить тест — должен пройти**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/EnumTitlesTest.php
```

Ожидание: PASS.

- [ ] **Step 7: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/CoatingSystem/ \
        app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/EnumTitlesTest.php
git commit -m "feat(coatings): add compliance and substrate enums for CoatingSystem"
```

---

## Task 2: `SurfacePreparation` VO

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparation.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparationTest.php`

**Interfaces:**
- Consumes: `App\Shared\Infrastructure\Exception\AppException`.
- Produces: `SurfacePreparation` VO с публичными `string $grade`, `string $description`, `?string $standard`. Конструктор бросает `AppException` при нарушении валидации.

- [ ] **Step 1: Написать проваливающиеся тесты**

`app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class SurfacePreparationTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $sp = new SurfacePreparation('Sa 2 1/2', 'Абразивоструйная очистка', 'ИСО 8501-1');
        self::assertSame('Sa 2 1/2', $sp->grade);
        self::assertSame('Абразивоструйная очистка', $sp->description);
        self::assertSame('ИСО 8501-1', $sp->standard);
    }

    public function test_standard_is_optional(): void
    {
        $sp = new SurfacePreparation('Wa 2', 'Гидроструйная');
        self::assertNull($sp->standard);
    }

    public function test_empty_grade_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('', 'x');
    }

    public function test_grade_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation(str_repeat('x', 31), '');
    }

    public function test_description_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('Sa 3', str_repeat('x', 501));
    }

    public function test_empty_standard_string_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('Sa 3', '', '');
    }

    public function test_standard_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('Sa 3', '', str_repeat('x', 51));
    }
}
```

- [ ] **Step 2: Запустить тест — FAIL (class not found)**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparationTest.php
```

- [ ] **Step 3: Написать реализацию `SurfacePreparation`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class SurfacePreparation
{
    public function __construct(
        public string $grade,
        public string $description,
        public ?string $standard = null,
    ) {
        $trimmedGrade = trim($this->grade);
        if ('' === $trimmedGrade) {
            throw new AppException('Обозначение подготовки поверхности не может быть пустым.');
        }
        if (mb_strlen($this->grade) > 30) {
            throw new AppException('Обозначение подготовки поверхности не должно превышать 30 символов.');
        }
        if (mb_strlen($this->description) > 500) {
            throw new AppException('Описание подготовки поверхности не должно превышать 500 символов.');
        }
        if (null !== $this->standard) {
            if ('' === trim($this->standard)) {
                throw new AppException('Обозначение стандарта не может быть пустой строкой (используйте null).');
            }
            if (mb_strlen($this->standard) > 50) {
                throw new AppException('Обозначение стандарта не должно превышать 50 символов.');
            }
        }
    }

    /** @return array{grade: string, description: string, standard: ?string} */
    public function toArray(): array
    {
        return [
            'grade' => $this->grade,
            'description' => $this->description,
            'standard' => $this->standard,
        ];
    }

    /** @param array{grade: string, description: string, standard: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['grade'], $data['description'], $data['standard'] ?? null);
    }
}
```

- [ ] **Step 4: Запустить — PASS**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparationTest.php
```

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparation.php \
        app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparationTest.php
git commit -m "feat(coatings): add SurfacePreparation VO for CoatingSystem"
```

---

## Task 3: Coating.isZincRich (домен + маппинг + миграция)

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` — новое поле, конструктор, сеттер, геттер.
- Modify: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` — колонка `is_zinc_rich`.
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260726120000.php` — `ALTER TABLE coating ADD COLUMN is_zinc_rich BOOLEAN NOT NULL DEFAULT FALSE`.
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php` (если есть) — покрыть новое поле.
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php` — новое поле.
- Modify: `app/src/Coatings/Application/UseCase/Command/{CreateCoating,UpdateCoating}/{Command,CommandHandler}.php` — прокинуть значение.
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` — обратный путь.
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php` — только shape.

**Interfaces:**
- Produces: `Coating::isZincRich(): bool`, `Coating::setIsZincRich(bool): void`. Дефолт `false` в конструкторе (backward-compat для существующих тестов).

- [ ] **Step 1: Написать провальный юнит-тест `Coating::isZincRich()`**

Добавить метод в существующий `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php` (или создать, если файла нет):

```php
public function test_is_zinc_rich_defaults_to_false(): void
{
    $coating = $this->buildValidCoating();
    self::assertFalse($coating->isZincRich());
}

public function test_is_zinc_rich_can_be_toggled(): void
{
    $coating = $this->buildValidCoating();
    $coating->setIsZincRich(true);
    self::assertTrue($coating->isZincRich());
    $coating->setIsZincRich(false);
    self::assertFalse($coating->isZincRich());
}
```

Если фабрики `buildValidCoating()` в тесте нет — использовать паттерн из уже существующих тестов Coating (см. `tests/Unit/Coatings/Domain/Aggregate/Coating/`).

- [ ] **Step 2: Запустить — FAIL**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php
```

Ожидание: `Method Coating::isZincRich() not found`.

- [ ] **Step 3: Добавить поле и методы в `Coating`**

В `Coating.php`:
- Добавить свойство `private bool $isZincRich = false;`
- Геттер `public function isZincRich(): bool { return $this->isZincRich; }`
- Сеттер `public function setIsZincRich(bool $value): void { $this->isZincRich = $value; }`
- В конструктор добавить nullable-опциональный параметр `bool $isZincRich = false` последним аргументом → `$this->setIsZincRich($isZincRich);`

- [ ] **Step 4: Расширить ORM XML**

В `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` добавить в блок `<entity>` внутри полей:

```xml
<field name="isZincRich" column="is_zinc_rich" type="boolean" nullable="false"/>
```

- [ ] **Step 5: Создать миграцию**

`app/src/Shared/Infrastructure/Database/Migrations/Version20260726120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coating.is_zinc_rich column for ISO 12944 compliance evaluation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coating ADD COLUMN IF NOT EXISTS is_zinc_rich BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coating DROP COLUMN IF EXISTS is_zinc_rich');
    }
}
```

- [ ] **Step 6: Прогнать миграцию локально**

```bash
cd app && bin/console doctrine:migrations:migrate -n
```

Ожидание: миграция применяется.

- [ ] **Step 7: Прокинуть поле через Application-слой**

Модификации (только shape, никаких if):
- `CoatingDTO` — добавить `public bool $isZincRich = false;`.
- `CreateCoatingCommand`, `UpdateCoatingCommand` — добавить `bool $isZincRich = false` в конструктор.
- `CreateCoatingCommandHandler`, `UpdateCoatingCommandHandler` — передать `$command->isZincRich` в конструктор/сеттер `Coating`.
- `CoatingDTOTransformer::fromEntity` — заполнить `$dto->isZincRich = $coating->isZincRich();`.
- `CoatingMapper::buildCoatingDtoFromInputData` — `$dto->isZincRich = (bool)($input['isZincRich'] ?? false);`.
- `CoatingMapper::buildInputDataFromDto` — `$input['isZincRich'] = $dto->isZincRich;`.
- `CoatingMapper::getValidationCollectionCoating` — Assert\Type('bool') на поле `isZincRich`.

- [ ] **Step 8: Добавить чекбокс в форму Coating**

В `app/templates/cabinet/coating/coating/form.html.twig` (или как называется существующий шаблон формы) добавить checkbox для `isZincRich`. Точное расположение: рядом с полем `base` (тип связующего) — логическая группа «свойства грунтовки».

Пример разметки (соблюсти существующий стиль формы):

```twig
<div class="form-check mb-3">
    <input type="checkbox" class="form-check-input" id="coating_is_zinc_rich"
           name="isZincRich" {% if data.isZincRich ?? false %}checked{% endif %}>
    <label class="form-check-label" for="coating_is_zinc_rich">
        Цинкнаполненная грунтовка (Zn(R), ≥80% Zn)
    </label>
</div>
```

- [ ] **Step 9: Запустить всё что связано с Coating**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating \
                            tests/Functional/Coatings/Infrastructure/Controller/Coating
```

Ожидание: всё зелёное. Если старые тесты падают — конструктор Coating изменил сигнатуру, поправить фабрики в тестах на именованные аргументы либо на дефолт.

- [ ] **Step 10: Пересобрать ассеты**

```bash
cd app && yarn dev
```

- [ ] **Step 11: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/Coating/Coating.php \
        app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml \
        app/src/Shared/Infrastructure/Database/Migrations/Version20260726120000.php \
        app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php \
        app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php \
        app/src/Coatings/Application/UseCase/Command/CreateCoating \
        app/src/Coatings/Application/UseCase/Command/UpdateCoating \
        app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php \
        app/templates/cabinet/coating/coating/form.html.twig \
        app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php
git commit -m "feat(coatings): add Coating.isZincRich flag and wire through UI"
```

---

## Task 4: `CoatingSystemLayer` entity + `CoatingSystem` агрегат (домен)

**Files:**
- Rewrite: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — переписать полностью (текущий файл — черновик).
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemLayer.php`.
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidator.php`.
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemTest.php`.
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidatorTest.php`.

**Interfaces produced:**
- `CoatingSystem` extends `App\Shared\Domain\Aggregate\Aggregate`. Конструктор:
  ```
  __construct(
      Uuid $id,
      string $title,
      string $description,
      Substrate $substrate,
      SurfacePreparation $surfacePreparation,
      CoatingSystemChainValidator $chainValidator,
  )
  ```
  Слои добавляются отдельными методами после конструктора (см. ниже).
- Мутирующие методы:
  ```
  appendLayer(Coating $coating, int $dft): CoatingSystemLayer
  insertLayerAt(int $position, Coating $coating, int $dft): CoatingSystemLayer
  removeLayerAt(int $position): void
  moveLayer(int $from, int $to): void
  updateLayerDft(int $position, int $dft): void
  ```
- Метаданные:
  ```
  setTitle(string): void
  setDescription(string): void
  setSubstrate(Substrate): void
  setSurfacePreparation(SurfacePreparation): void
  ```
- Read-side:
  ```
  getId(): string
  getTitle(): string
  getDescription(): string
  getSubstrate(): Substrate
  getSurfacePreparation(): SurfacePreparation
  getLayers(): Collection<CoatingSystemLayer>   // отсортировано по position ASC
  firstLayer(): CoatingSystemLayer
  followupLayers(): iterable<CoatingSystemLayer>
  totalDft(): int
  layerCount(): int
  getCreatedAt(): \DateTimeImmutable
  getUpdatedAt(): \DateTimeImmutable
  ```

- `CoatingSystemLayer`:
  ```
  __construct(Uuid $id, CoatingSystem $system, Coating $coating, int $position, int $dft)
  getId(): string
  getCoating(): Coating
  getPosition(): int
  getDft(): int
  changeDft(int $dft): void           // валидирует по coating.dftRange
  changePosition(int $position): void  // internal для агрегата
  ```

- `CoatingSystemChainValidator::validate(CoatingSystem $system): void`
  бросает `AppException` если для хоть одной пары `(i, i+1)`: `layers[i].coating.base.canBecoveredBy(layers[i+1].coating.base) === false`.

- [ ] **Step 1: Написать проваливающиеся тесты для CoatingSystem**

`app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemTest.php` — включить как минимум:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemTest extends TestCase
{
    // Фабрики Coating и CoatingSystem — вспомогательные методы, использующие
    // существующие тестовые билдеры Coating (см. tests/Unit/Coatings/Domain/Aggregate/Coating).

    public function test_construction_sets_metadata_and_timestamps(): void
    {
        $sys = $this->newSystem(title: 'Sys');
        self::assertSame('Sys', $sys->getTitle());
        self::assertSame(0, $sys->layerCount());
        self::assertNotNull($sys->getCreatedAt());
        self::assertEquals($sys->getCreatedAt(), $sys->getUpdatedAt());
    }

    public function test_append_layer_assigns_position_1(): void
    {
        $sys = $this->newSystem();
        $layer = $sys->appendLayer($this->newCoatingCompatibleAll(), 120);
        self::assertSame(1, $layer->getPosition());
        self::assertSame(1, $sys->layerCount());
        self::assertSame(120, $sys->totalDft());
    }

    public function test_append_two_layers_second_gets_position_2(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $l2 = $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        self::assertSame(2, $l2->getPosition());
        self::assertSame([1, 2], $this->positions($sys));
    }

    public function test_insert_at_shifts_positions(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->insertLayerAt(2, $this->newCoatingCompatibleAll(), 80);
        self::assertSame([1, 2, 3], $this->positions($sys));
        self::assertSame(3, $sys->layerCount());
    }

    public function test_remove_at_compacts_positions(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 40);
        $sys->removeLayerAt(2);
        self::assertSame([1, 2], $this->positions($sys));
        self::assertSame(100, $sys->totalDft());
    }

    public function test_move_layer(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 40);
        $sys->moveLayer(1, 3);
        self::assertSame([1, 2, 3], $this->positions($sys));
        // Первый слой ушёл на позицию 3
    }

    public function test_update_layer_dft(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->updateLayerDft(1, 90);
        self::assertSame(90, $sys->totalDft());
    }

    public function test_dft_outside_coating_range_throws(): void
    {
        $sys = $this->newSystem();
        $coating = $this->newCoatingWithDftRange(80, 150);
        $this->expectException(AppException::class);
        $sys->appendLayer($coating, 200);
    }

    public function test_incompatible_neighbors_throws(): void
    {
        // Смоделировать через два реальных Coating, где base1.canBecoveredBy(base2) = false
        // (например AY поверх ESI — совместимо, но ESI поверх AK — нет; см. CoatingBase::allowedPrimers)
        // ...
    }

    // Вспомогательные фабрики newSystem, newCoatingCompatibleAll,
    // newCoatingWithDftRange, positions — оформить как private-методы этого класса.
}
```

Также `CoatingSystemChainValidatorTest.php` — минимум два кейса: (a) пустая система = ok; (b) реально несовместимые соседи → AppException.

- [ ] **Step 2: Запустить — FAIL (нет классов)**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemTest.php
```

- [ ] **Step 3: Реализовать `CoatingSystemChainValidator`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Shared\Infrastructure\Exception\AppException;

final class CoatingSystemChainValidator
{
    public function validate(CoatingSystem $system): void
    {
        $layers = $system->getLayers()->toArray();
        $n = count($layers);
        for ($i = 0; $i < $n - 1; $i++) {
            $current = $layers[$i]->getCoating()->getBase();
            $next    = $layers[$i + 1]->getCoating()->getBase();
            if (!$current->canBecoveredBy($next)) {
                throw new AppException(sprintf(
                    'Слой %d (%s) несовместим со слоем %d (%s): поверх %s нельзя наносить %s.',
                    $i + 1, $current->title(),
                    $i + 2, $next->title(),
                    $current->title(), $next->title(),
                ));
            }
        }
    }
}
```

- [ ] **Step 4: Реализовать `CoatingSystemLayer`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemLayer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

class CoatingSystemLayer
{
    public readonly Uuid $id;

    public function __construct(
        Uuid $id,
        private CoatingSystem $system,
        private Coating $coating,
        private int $position,
        private int $dft,
    ) {
        $this->id = $id;
        $this->assertPositionValid($position);
        $this->assertDftInCoatingRange($dft, $coating);
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getSystem(): CoatingSystem
    {
        return $this->system;
    }

    public function getCoating(): Coating
    {
        return $this->coating;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getDft(): int
    {
        return $this->dft;
    }

    /** @internal вызывается только агрегатом */
    public function changePosition(int $position): void
    {
        $this->assertPositionValid($position);
        $this->position = $position;
    }

    public function changeDft(int $dft): void
    {
        $this->assertDftInCoatingRange($dft, $this->coating);
        $this->dft = $dft;
    }

    private function assertPositionValid(int $position): void
    {
        if ($position < 1) {
            throw new AppException('Позиция слоя должна быть ≥ 1.');
        }
    }

    private function assertDftInCoatingRange(int $dft, Coating $coating): void
    {
        $range = $coating->getDftRange();
        if (!$range->contains($dft)) {
            throw new AppException(sprintf(
                'Толщина слоя %d мкм вне допустимого диапазона покрытия «%s» (%d–%d мкм).',
                $dft, $coating->getTitle(), $range->getMin(), $range->getMax(),
            ));
        }
    }
}
```

(Точные имена методов `DftRange::contains`, `getMin`, `getMax` подтвердить — прочитать `app/src/Coatings/Domain/Aggregate/Coating/DftRange.php`. Если сигнатуры другие — подстроить, но семантика та же.)

- [ ] **Step 5: Реализовать `CoatingSystem`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` (переписать полностью, удалив весь черновик):

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Symfony\Component\Uid\Uuid;

class CoatingSystem extends Aggregate
{
    public readonly Uuid $id;

    private string $title;
    private string $description;
    private Substrate $substrate;
    private SurfacePreparation $surfacePreparation;
    /** @var Collection<int, CoatingSystemLayer> */
    private Collection $layers;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        string $title,
        string $description,
        Substrate $substrate,
        SurfacePreparation $surfacePreparation,
        private readonly CoatingSystemChainValidator $chainValidator,
    ) {
        $this->id = $id;
        $this->layers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setSubstrate($substrate);
        $this->setSurfacePreparation($surfacePreparation);
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getTitle(): string    { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getSubstrate(): Substrate { return $this->substrate; }
    public function getSurfacePreparation(): SurfacePreparation { return $this->surfacePreparation; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setTitle(string $title): void
    {
        $trimmed = trim($title);
        if ('' === $trimmed) {
            throw new AppException('Название системы покрытий не может быть пустым.');
        }
        if (mb_strlen($title) > 100) {
            throw new AppException('Название системы покрытий не должно превышать 100 символов.');
        }
        $this->title = $title;
        $this->touch();
    }

    public function setDescription(string $description): void
    {
        if (mb_strlen($description) > 2000) {
            throw new AppException('Описание системы покрытий не должно превышать 2000 символов.');
        }
        $this->description = $description;
        $this->touch();
    }

    public function setSubstrate(Substrate $substrate): void
    {
        $this->substrate = $substrate;
        $this->touch();
    }

    public function setSurfacePreparation(SurfacePreparation $surfacePreparation): void
    {
        $this->surfacePreparation = $surfacePreparation;
        $this->touch();
    }

    /** @return Collection<int, CoatingSystemLayer> */
    public function getLayers(): Collection
    {
        $criteria = Criteria::create()->orderBy(['position' => Criteria::ASC]);
        return $this->layers->matching($criteria);
    }

    public function layerCount(): int
    {
        return $this->layers->count();
    }

    public function totalDft(): int
    {
        $sum = 0;
        foreach ($this->layers as $layer) {
            $sum += $layer->getDft();
        }
        return $sum;
    }

    public function firstLayer(): CoatingSystemLayer
    {
        $sorted = $this->getLayers()->toArray();
        if ([] === $sorted) {
            throw new AppException('Система покрытий пуста, слоёв нет.');
        }
        return $sorted[0];
    }

    /** @return iterable<CoatingSystemLayer> */
    public function followupLayers(): iterable
    {
        $sorted = $this->getLayers()->toArray();
        return array_slice($sorted, 1);
    }

    public function appendLayer(Coating $coating, int $dft): CoatingSystemLayer
    {
        $position = $this->layerCount() + 1;
        $layer = new CoatingSystemLayer(Uuid::v7(), $this, $coating, $position, $dft);
        $this->layers->add($layer);
        $this->postMutate();
        return $layer;
    }

    public function insertLayerAt(int $position, Coating $coating, int $dft): CoatingSystemLayer
    {
        if ($position < 1 || $position > $this->layerCount() + 1) {
            throw new AppException(sprintf(
                'Позиция вставки %d вне диапазона 1..%d.',
                $position, $this->layerCount() + 1,
            ));
        }
        foreach ($this->getLayers() as $existing) {
            if ($existing->getPosition() >= $position) {
                $existing->changePosition($existing->getPosition() + 1);
            }
        }
        $layer = new CoatingSystemLayer(Uuid::v7(), $this, $coating, $position, $dft);
        $this->layers->add($layer);
        $this->postMutate();
        return $layer;
    }

    public function removeLayerAt(int $position): void
    {
        $target = null;
        foreach ($this->layers as $layer) {
            if ($layer->getPosition() === $position) {
                $target = $layer;
                break;
            }
        }
        if (null === $target) {
            throw new AppException(sprintf('Слой с позицией %d не найден.', $position));
        }
        $this->layers->removeElement($target);
        foreach ($this->getLayers() as $existing) {
            if ($existing->getPosition() > $position) {
                $existing->changePosition($existing->getPosition() - 1);
            }
        }
        $this->postMutate();
    }

    public function moveLayer(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }
        $count = $this->layerCount();
        if ($from < 1 || $from > $count || $to < 1 || $to > $count) {
            throw new AppException(sprintf('Некорректные позиции move: from=%d, to=%d (диапазон 1..%d).', $from, $to, $count));
        }
        $target = null;
        foreach ($this->layers as $layer) {
            if ($layer->getPosition() === $from) {
                $target = $layer;
                break;
            }
        }
        if (null === $target) {
            throw new AppException(sprintf('Слой с позицией %d не найден.', $from));
        }
        if ($from < $to) {
            foreach ($this->layers as $layer) {
                if ($layer !== $target && $layer->getPosition() > $from && $layer->getPosition() <= $to) {
                    $layer->changePosition($layer->getPosition() - 1);
                }
            }
        } else {
            foreach ($this->layers as $layer) {
                if ($layer !== $target && $layer->getPosition() >= $to && $layer->getPosition() < $from) {
                    $layer->changePosition($layer->getPosition() + 1);
                }
            }
        }
        $target->changePosition($to);
        $this->postMutate();
    }

    public function updateLayerDft(int $position, int $dft): void
    {
        foreach ($this->layers as $layer) {
            if ($layer->getPosition() === $position) {
                $layer->changeDft($dft);
                $this->postMutate();
                return;
            }
        }
        throw new AppException(sprintf('Слой с позицией %d не найден.', $position));
    }

    private function postMutate(): void
    {
        $this->assertPositionsAreDense();
        $this->chainValidator->validate($this);
        $this->touch();
    }

    private function assertPositionsAreDense(): void
    {
        $positions = [];
        foreach ($this->layers as $layer) {
            $positions[] = $layer->getPosition();
        }
        sort($positions);
        $expected = range(1, count($positions));
        if ($positions !== $expected) {
            throw new AppException(sprintf(
                'Позиции слоёв нарушены: [%s], ожидалось [%s].',
                implode(',', $positions), implode(',', $expected),
            ));
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 6: Запустить unit-тесты — PASS**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem
```

Ожидание: все зелёные. Если фабрики Coating в тестах требуют изменённую сигнатуру — поправить.

- [ ] **Step 7: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php \
        app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemLayer.php \
        app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidator.php \
        app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemTest.php \
        app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidatorTest.php
git commit -m "feat(coatings): implement CoatingSystem aggregate with layer chain invariants"
```

---

## Task 5: `ComplianceRule` VO + `ComplianceRuleBook` (пустой) + `ComplianceEvaluator`

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRule.php`
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBook.php` (пустой массив пока)
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluatorTest.php`

**Interfaces:**
- Consumes: `Substrate`, `ComplianceStandard`, `PrimerType`, `CoatingBase`, `CoatingSystem`.
- Produces:
  - `ComplianceRule` — `final readonly` VO с публичными полями (см. spec).
  - `ComplianceRuleBook::rules(): array<int, ComplianceRule>` — пока `return [];`, наполняется в Task 6. Публичный статический метод, чтобы тесты Task 6 могли его переопределить (не смогут без monkey-patching — используем DI-friendly подход: evaluator принимает `array $rules` в конструкторе).
  - `ComplianceEvaluator::__construct(iterable $rules)` — принимает список правил (DI).
  - `ComplianceEvaluator::evaluate(CoatingSystem $system): array` — возвращает `list<array{standard: ComplianceStandard, category: string, durability: string}>`.

- [ ] **Step 1: Тест с in-memory rule set**

`app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceRule;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\PrimerType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use PHPUnit\Framework\TestCase;

final class ComplianceEvaluatorTest extends TestCase
{
    public function test_matches_when_system_satisfies_rule(): void
    {
        $rule = new ComplianceRule(
            standard: ComplianceStandard::ISO_12944,
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 2,
            ndft: 160,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        // Собрать CoatingSystem с 2 слоями Zn(R) EP → EP+PUR, totalDft=180.
        // Вспомогательный билдер makeSystem() принимает список [(base, dft, isZincRich)].
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
            [CoatingBase::PUR, 100, false],
        ]);

        $result = $evaluator->evaluate($system);
        self::assertCount(1, $result);
        self::assertSame(ComplianceStandard::ISO_12944, $result[0]['standard']);
        self::assertSame('C3', $result[0]['category']);
        self::assertSame('HIGH', $result[0]['durability']);
    }

    public function test_no_match_when_ndft_insufficient(): void { /* ... */ }
    public function test_no_match_when_primer_binder_not_allowed(): void { /* ... */ }
    public function test_no_match_when_followup_binder_not_allowed(): void { /* ... */ }
    public function test_no_match_when_primer_type_differs(): void { /* ... */ }
    public function test_no_match_when_substrate_differs(): void { /* ... */ }

    // makeSystem, makeCoating — private-фабрики (используют реальные объекты, не моки).
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluatorTest.php
```

- [ ] **Step 3: Реализовать `ComplianceRule`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;

final readonly class ComplianceRule
{
    /**
     * @param list<CoatingBase> $primerBinders
     * @param list<CoatingBase> $otherBinders
     */
    public function __construct(
        public ComplianceStandard $standard,
        public Substrate $substrate,
        public string $category,
        public string $durability,
        public PrimerType $primerType,
        public int $mnoc,
        public int $ndft,
        public array $primerBinders,
        public array $otherBinders,
    ) {}
}
```

- [ ] **Step 4: Реализовать `ComplianceEvaluator`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

final class ComplianceEvaluator
{
    /**
     * @param iterable<ComplianceRule> $rules
     */
    public function __construct(private readonly iterable $rules) {}

    /**
     * @return list<array{standard: ComplianceStandard, category: string, durability: string}>
     */
    public function evaluate(CoatingSystem $system): array
    {
        if (0 === $system->layerCount()) {
            return [];
        }

        $first = $system->firstLayer()->getCoating();
        $primerType = $first->isZincRich() ? PrimerType::ZINC_RICH : PrimerType::OTHER;
        $ndft = $system->totalDft();
        $mnoc = $system->layerCount();
        $primerBase = $first->getBase();
        $followupBases = [];
        foreach ($system->followupLayers() as $layer) {
            $followupBases[] = $layer->getCoating()->getBase();
        }

        $result = [];
        foreach ($this->rules as $rule) {
            if ($rule->substrate !== $system->getSubstrate())            continue;
            if ($rule->primerType !== $primerType)                       continue;
            if ($mnoc < $rule->mnoc)                                     continue;
            if ($ndft < $rule->ndft)                                     continue;
            if (!in_array($primerBase, $rule->primerBinders, true))      continue;
            $mismatch = false;
            foreach ($followupBases as $base) {
                if (!in_array($base, $rule->otherBinders, true)) {
                    $mismatch = true;
                    break;
                }
            }
            if ($mismatch) continue;
            $result[] = [
                'standard'   => $rule->standard,
                'category'   => $rule->category,
                'durability' => $rule->durability,
            ];
        }
        return $result;
    }
}
```

- [ ] **Step 5: Пустой `ComplianceRuleBook`**

`app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

final class ComplianceRuleBook
{
    /** @return list<ComplianceRule> */
    public static function rules(): array
    {
        // Наполняется в Task 6 (правила ISO 12944 из таблиц B.2..B.5).
        return [];
    }
}
```

- [ ] **Step 6: Запустить — PASS**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluatorTest.php
```

- [ ] **Step 7: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRule.php \
        app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php \
        app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBook.php \
        app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluatorTest.php
git commit -m "feat(coatings): add ComplianceRule VO, ComplianceEvaluator, empty RuleBook"
```

---

## Task 6: Наполнение `ComplianceRuleBook` таблицами B.2..B.5

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBook.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBookTest.php`

**Interfaces:**
- Produces: непустой `ComplianceRuleBook::rules()` — ~80 записей, покрывающих ISO 12944 таблицы B.2 (сталь+Sa 2½), B.3 (горячее цинкование), B.4 (термически напылённая), B.5 (другие поверхности).

**Reference:** Читать `docs/plans/coating-system/design.md` (раздел «Механика расчёта»). Первоисточник — ГОСТ 34667.5—2021, скачанный локально в `/tmp/gost/76970.pdf` (страницы 22-24 для таблицы B.2, дальше B.3..B.5). Если PDF не доступен — качать: `curl -sL -o /tmp/gost/76970.pdf 'https://files.stroyinf.ru/Data/769/76970.pdf'`, читать через `Read` tool с параметром `pages`.

- [ ] **Step 1: Тест на несколько эталонных строк из B.2**

`app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBookTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceRuleBook;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\PrimerType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use PHPUnit\Framework\TestCase;

final class ComplianceRuleBookTest extends TestCase
{
    public function test_rule_book_is_not_empty(): void
    {
        self::assertNotEmpty(ComplianceRuleBook::rules());
    }

    /**
     * Из таблицы B.2 ГОСТ 34667.5-2021:
     * Сталь + Sa 2½, C3, High, Zn(R): MNOC=2, NDFT=160, primer=EP/PUR, followup=EP/PUR/AK/AY.
     */
    public function test_rule_c3_high_zn_r_present(): void
    {
        $rules = array_filter(ComplianceRuleBook::rules(), static fn ($r) =>
            $r->standard === ComplianceStandard::ISO_12944
            && $r->substrate === Substrate::STEEL_CARBON
            && $r->category === 'C3'
            && $r->durability === 'HIGH'
            && $r->primerType === PrimerType::ZINC_RICH
        );
        self::assertCount(1, $rules);
        $rule = array_values($rules)[0];
        self::assertSame(2, $rule->mnoc);
        self::assertSame(160, $rule->ndft);
        self::assertContains(CoatingBase::EP, $rule->primerBinders);
        self::assertContains(CoatingBase::PUR, $rule->primerBinders);
    }

    // Аналогичные тесты для 4-5 других эталонных строк (по одной из каждой из B.2, B.3, B.4, B.5).
}
```

- [ ] **Step 2: Запустить — FAIL (пустой rulebook)**

- [ ] **Step 3: Наполнить `ComplianceRuleBook::rules()` данными**

Прочитать таблицу B.2 (стр 23 PDF), извлечь строки. Каждая ячейка `MNOC` + `NDFT` под конкретной колонкой `(долговечность × тип грунта)` → одно правило. Аналогично B.3, B.4, B.5. Правило записывается через конструктор `ComplianceRule`.

Пример (одна строка из B.2 для сокращения места; в реальном коде — все строки):

```php
return [
    new ComplianceRule(
        standard: ComplianceStandard::ISO_12944,
        substrate: Substrate::STEEL_CARBON,
        category: 'C2',
        durability: 'MEDIUM',
        primerType: PrimerType::ZINC_RICH,
        mnoc: 1,
        ndft: 60,
        primerBinders: [CoatingBase::ESI, CoatingBase::EP, CoatingBase::PUR],
        otherBinders: [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AK, CoatingBase::AY],
    ),
    // …все остальные строки таблиц B.2, B.3, B.4, B.5.
];
```

Пропускать пустые ячейки таблицы (в ГОСТ отмечены «—»). Значение долговечности (`l` / `m` / `h` / `vh`) переводить в `IsoDurability::LOW->value` и т.д.

- [ ] **Step 4: Запустить — PASS**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBookTest.php
```

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBook.php \
        app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBookTest.php
git commit -m "feat(coatings): populate ComplianceRuleBook with ISO 12944 tables B.2-B.5"
```

---

## Task 7: DBAL type + ORM mapping + Repository + миграция таблиц

**Files:**
- Create: `app/src/Coatings/Infrastructure/Database/DBAL/SurfacePreparationType.php`
- Create: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml`
- Create: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystemLayer.orm.xml`
- Create: `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php`
- Create: `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php`
- Create: `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php`
- Modify: `app/config/packages/doctrine.yaml` — регистрация `SurfacePreparationType` + пути ORM.
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260726120100.php` — таблицы `coating_system`, `coating_system_layer`.
- Test: `app/tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryTest.php`

**Interfaces:**
- `SurfacePreparationType extends JsonType`, `getName(): 'surface_preparation'`, `convertToPHPValue` → `SurfacePreparation::fromArray`, `convertToDatabaseValue` → parent (VO `JsonSerializable` не обязательно — сериализуем через toArray()).
- `CoatingSystemRepositoryInterface` методы:
  ```
  save(CoatingSystem $system): void
  remove(CoatingSystem $system): void
  findById(Uuid $id): ?CoatingSystem
  list(CoatingSystemsFilter $filter, int $limit, int $offset): array
  count(CoatingSystemsFilter $filter): int
  findByCompliance(ComplianceStandard $s, string $category, string $durability, ?Substrate $substrate, int $limit, int $offset): array
  ```
- `CoatingSystemsFilter` — dumb DTO с полями `?string $titleLike`, `?Substrate $substrate`.

**Reference:** Читать `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` для стиля XML-маппинга.

- [ ] **Step 1: `SurfacePreparationType`**

`app/src/Coatings/Infrastructure/Database/DBAL/SurfacePreparationType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class SurfacePreparationType extends JsonType
{
    public const NAME = 'surface_preparation';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?SurfacePreparation
    {
        if (null === $value) return null;
        $data = parent::convertToPHPValue($value, $platform);
        return SurfacePreparation::fromArray($data);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if (null === $value) return null;
        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }
}
```

- [ ] **Step 2: Регистрация типа**

В `app/config/packages/doctrine.yaml` в блоке `dbal.types`:

```yaml
surface_preparation: App\Coatings\Infrastructure\Database\DBAL\SurfacePreparationType
```

- [ ] **Step 3: ORM XML для CoatingSystem**

`app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                    https://raw.githubusercontent.com/doctrine/orm/2.14.x/doctrine-mapping.xsd">
    <entity name="App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem" table="coating_system">
        <id name="id" type="uuid" column="id"/>
        <field name="title"       type="string" length="100" nullable="false"/>
        <field name="description" type="text" length="2000" nullable="false"/>
        <field name="substrate"   type="string" length="32" nullable="false" enum-type="App\Coatings\Domain\Aggregate\CoatingSystem\Substrate"/>
        <field name="surfacePreparation" column="surface_preparation" type="surface_preparation" nullable="false"/>
        <field name="createdAt" column="created_at" type="datetime_immutable" nullable="false"/>
        <field name="updatedAt" column="updated_at" type="datetime_immutable" nullable="false"/>

        <one-to-many field="layers"
                     target-entity="App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemLayer"
                     mapped-by="system">
            <cascade>
                <cascade-persist/>
                <cascade-remove/>
            </cascade>
            <order-by>
                <order-by-field name="position" direction="ASC"/>
            </order-by>
        </one-to-many>
    </entity>
</doctrine-mapping>
```

- [ ] **Step 4: ORM XML для CoatingSystemLayer**

`app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystemLayer.orm.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <entity name="App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemLayer" table="coating_system_layer">
        <id name="id" type="uuid" column="id"/>
        <field name="position" type="integer" nullable="false"/>
        <field name="dft" type="integer" nullable="false"/>

        <many-to-one field="system"
                     target-entity="App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem"
                     inversed-by="layers">
            <join-column name="system_id" referenced-column-name="id" nullable="false" on-delete="CASCADE"/>
        </many-to-one>

        <many-to-one field="coating"
                     target-entity="App\Coatings\Domain\Aggregate\Coating\Coating">
            <join-column name="coating_id" referenced-column-name="id" nullable="false"/>
        </many-to-one>

        <unique-constraints>
            <unique-constraint name="uniq_csl_system_position" columns="system_id,position"/>
        </unique-constraints>
    </entity>
</doctrine-mapping>
```

- [ ] **Step 5: Миграция таблиц**

`app/src/Shared/Infrastructure/Database/Migrations/Version20260726120100.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create coating_system and coating_system_layer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS coating_system (
                id                    UUID PRIMARY KEY,
                title                 VARCHAR(100)  NOT NULL,
                description           TEXT          NOT NULL DEFAULT '',
                substrate             VARCHAR(32)   NOT NULL,
                surface_preparation   JSONB         NOT NULL,
                created_at            TIMESTAMPTZ   NOT NULL,
                updated_at            TIMESTAMPTZ   NOT NULL
            )
        SQL);
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS coating_system_layer (
                id          UUID PRIMARY KEY,
                system_id   UUID NOT NULL REFERENCES coating_system(id) ON DELETE CASCADE,
                coating_id  UUID NOT NULL REFERENCES coating(id) ON DELETE RESTRICT,
                position    INT  NOT NULL CHECK (position >= 1),
                dft         INT  NOT NULL CHECK (dft >= 1),
                CONSTRAINT uniq_csl_system_position UNIQUE (system_id, position)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_csl_system ON coating_system_layer (system_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS coating_system_layer');
        $this->addSql('DROP TABLE IF EXISTS coating_system');
    }
}
```

- [ ] **Step 6: Прогнать миграцию**

```bash
cd app && bin/console doctrine:migrations:migrate -n
cd app && bin/console doctrine:schema:validate  # ожидание: sync
```

- [ ] **Step 7: `CoatingSystemsFilter` + `CoatingSystemRepositoryInterface`**

`app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;

final readonly class CoatingSystemsFilter
{
    public function __construct(
        public ?string $titleLike = null,
        public ?Substrate $substrate = null,
    ) {}
}
```

`app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use Symfony\Component\Uid\Uuid;

interface CoatingSystemRepositoryInterface
{
    public function save(CoatingSystem $system): void;
    public function remove(CoatingSystem $system): void;
    public function findById(Uuid $id): ?CoatingSystem;

    /** @return list<CoatingSystem> */
    public function list(CoatingSystemsFilter $filter, int $limit, int $offset): array;

    public function count(CoatingSystemsFilter $filter): int;

    /** @return list<CoatingSystem> */
    public function findByCompliance(
        ComplianceStandard $standard,
        string $category,
        string $durability,
        ?Substrate $substrate,
        int $limit,
        int $offset,
    ): array;
}
```

- [ ] **Step 8: Doctrine-имплементация**

`app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemRepository implements CoatingSystemRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(CoatingSystem $system): void
    {
        $this->em->persist($system);
        $this->em->flush();
    }

    public function remove(CoatingSystem $system): void
    {
        $this->em->remove($system);
        $this->em->flush();
    }

    public function findById(Uuid $id): ?CoatingSystem
    {
        return $this->em->find(CoatingSystem::class, $id);
    }

    public function list(CoatingSystemsFilter $filter, int $limit, int $offset): array
    {
        $qb = $this->em->createQueryBuilder()->select('s')->from(CoatingSystem::class, 's');
        $this->applyFilter($qb, $filter);
        $qb->orderBy('s.updatedAt', 'DESC')->setFirstResult($offset)->setMaxResults($limit);
        return $qb->getQuery()->getResult();
    }

    public function count(CoatingSystemsFilter $filter): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(s.id)')->from(CoatingSystem::class, 's');
        $this->applyFilter($qb, $filter);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findByCompliance(
        ComplianceStandard $standard,
        string $category,
        string $durability,
        ?Substrate $substrate,
        int $limit,
        int $offset,
    ): array {
        $sql = <<<SQL
            SELECT s.id FROM coating_system s
            INNER JOIN coating_system_compliance c ON c.system_id = s.id
            WHERE c.standard = :standard AND c.category = :category AND c.durability = :durability
            SQL;
        $params = [
            'standard' => $standard->value,
            'category' => $category,
            'durability' => $durability,
        ];
        if (null !== $substrate) {
            $sql .= ' AND s.substrate = :substrate';
            $params['substrate'] = $substrate->value;
        }
        $sql .= ' ORDER BY s.updated_at DESC LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $ids = array_column($this->em->getConnection()->fetchAllAssociative($sql, $params), 'id');
        if ([] === $ids) return [];

        return $this->em->createQueryBuilder()
            ->select('s')->from(CoatingSystem::class, 's')
            ->where('s.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
    }

    private function applyFilter($qb, CoatingSystemsFilter $filter): void
    {
        if (null !== $filter->titleLike && '' !== $filter->titleLike) {
            $qb->andWhere('LOWER(s.title) LIKE LOWER(:t)')->setParameter('t', '%'.$filter->titleLike.'%');
        }
        if (null !== $filter->substrate) {
            $qb->andWhere('s.substrate = :sub')->setParameter('sub', $filter->substrate->value);
        }
    }
}
```

Регистрация:

`app/config/services.yaml` — добавить:

```yaml
App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface:
    class: App\Coatings\Infrastructure\Repository\CoatingSystemRepository
```

- [ ] **Step 9: Functional test репозитория**

`app/tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryTest.php` — тест round-trip:
создать `CoatingSystem` в памяти, `save`, `findById`, проверить восстановление всех полей включая слои и SurfacePreparation.

- [ ] **Step 10: Прогнать функциональные тесты**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryTest.php
```

Ожидание: PASS.

- [ ] **Step 11: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Database/DBAL/SurfacePreparationType.php \
        app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.*.orm.xml \
        app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php \
        app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php \
        app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php \
        app/config/packages/doctrine.yaml \
        app/config/services.yaml \
        app/src/Shared/Infrastructure/Database/Migrations/Version20260726120100.php \
        app/tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryTest.php
git commit -m "feat(coatings): add CoatingSystem persistence layer with DBAL SurfacePreparation type"
```

---

## Task 8: Индекс `coating_system_compliance` + `CoatingSystemComplianceProjector`

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260726120200.php` — таблица `coating_system_compliance`.
- Create: `app/src/Coatings/Infrastructure/Projector/CoatingSystemComplianceProjector.php` — Doctrine listener.
- Modify: `app/config/services.yaml` — регистрация listener-а с тегами.
- Modify: `app/config/services.yaml` — регистрация `ComplianceEvaluator` с DI-параметром `!php/const` или через фабрику из `ComplianceRuleBook::rules()`.
- Test: `app/tests/Functional/Coatings/Infrastructure/Projector/CoatingSystemComplianceProjectorTest.php`

**Interfaces:**
- Consumes: `ComplianceEvaluator`, `EntityManagerInterface`, `Doctrine\ORM\Event\PostPersistEventArgs`, `PostUpdateEventArgs`.
- Produces: side-effect — таблица `coating_system_compliance` синхронизирована с результатами `ComplianceEvaluator` для каждой CoatingSystem.

- [ ] **Step 1: Миграция таблицы**

`app/src/Shared/Infrastructure/Database/Migrations/Version20260726120200.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726120200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create coating_system_compliance index table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS coating_system_compliance (
                system_id  UUID        NOT NULL REFERENCES coating_system(id) ON DELETE CASCADE,
                standard   VARCHAR(32) NOT NULL,
                category   VARCHAR(16) NOT NULL,
                durability VARCHAR(16) NOT NULL,
                PRIMARY KEY (system_id, standard, category, durability)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_csc_search ON coating_system_compliance (standard, category, durability, system_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS coating_system_compliance');
    }
}
```

- [ ] **Step 2: Прогнать миграцию**

```bash
cd app && bin/console doctrine:migrations:migrate -n
```

- [ ] **Step 3: Реализовать `CoatingSystemComplianceProjector`**

`app/src/Coatings/Infrastructure/Projector/CoatingSystemComplianceProjector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Projector;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class CoatingSystemComplianceProjector
{
    public function __construct(private readonly ComplianceEvaluator $evaluator) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CoatingSystem) return;
        $this->rebuild($entity, $args->getObjectManager()->getConnection());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CoatingSystem) return;
        $this->rebuild($entity, $args->getObjectManager()->getConnection());
    }

    private function rebuild(CoatingSystem $system, $conn): void
    {
        $conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $system->getId()],
        );
        $matches = $this->evaluator->evaluate($system);
        foreach ($matches as $m) {
            $conn->executeStatement(
                'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
                 VALUES (:id, :std, :cat, :dur)',
                [
                    'id'  => $system->getId(),
                    'std' => $m['standard']->value,
                    'cat' => $m['category'],
                    'dur' => $m['durability'],
                ],
            );
        }
    }
}
```

- [ ] **Step 4: Регистрация в services.yaml**

`app/config/services.yaml`:

```yaml
App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator:
    factory: [App\Coatings\Infrastructure\Factory\ComplianceEvaluatorFactory, create]

App\Coatings\Infrastructure\Factory\ComplianceEvaluatorFactory: ~

App\Coatings\Infrastructure\Projector\CoatingSystemComplianceProjector: ~
```

Создать фабрику `app/src/Coatings/Infrastructure/Factory/ComplianceEvaluatorFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Factory;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceRuleBook;

final class ComplianceEvaluatorFactory
{
    public static function create(): ComplianceEvaluator
    {
        return new ComplianceEvaluator(ComplianceRuleBook::rules());
    }
}
```

- [ ] **Step 5: Functional test**

`app/tests/Functional/Coatings/Infrastructure/Projector/CoatingSystemComplianceProjectorTest.php`:

Создать CoatingSystem, сохранить через репозиторий, проверить `SELECT` из `coating_system_compliance` содержит ожидаемые строки. Изменить слой (`updateLayerDft`), пересохранить, проверить что строки пересчитались. Удалить систему — строки исчезли (cascade).

- [ ] **Step 6: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Projector/CoatingSystemComplianceProjectorTest.php
```

- [ ] **Step 7: Коммит**

```bash
git add app/src/Shared/Infrastructure/Database/Migrations/Version20260726120200.php \
        app/src/Coatings/Infrastructure/Projector/CoatingSystemComplianceProjector.php \
        app/src/Coatings/Infrastructure/Factory/ComplianceEvaluatorFactory.php \
        app/config/services.yaml \
        app/tests/Functional/Coatings/Infrastructure/Projector/CoatingSystemComplianceProjectorTest.php
git commit -m "feat(coatings): add coating_system_compliance projector"
```

---

## Task 9: Console command `coatings:system-compliance:rebuild`

**Files:**
- Create: `app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemComplianceCommand.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Console/RebuildCoatingSystemComplianceCommandTest.php`

**Interfaces:**
- Consumes: `CoatingSystemRepositoryInterface`, `ComplianceEvaluator`, `EntityManagerInterface`.
- Produces: команда `coatings:system-compliance:rebuild` — TRUNCATE таблицы + пересчёт для всех систем.

- [ ] **Step 1: Реализовать команду**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Console;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'coatings:system-compliance:rebuild', description: 'Recompute coating_system_compliance index for all systems.')]
final class RebuildCoatingSystemComplianceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('TRUNCATE TABLE coating_system_compliance');

        $count = 0;
        foreach ($this->em->getRepository(CoatingSystem::class)->findAll() as $system) {
            /** @var CoatingSystem $system */
            $matches = $this->evaluator->evaluate($system);
            foreach ($matches as $m) {
                $conn->executeStatement(
                    'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
                     VALUES (:id, :std, :cat, :dur)',
                    [
                        'id'  => $system->getId(),
                        'std' => $m['standard']->value,
                        'cat' => $m['category'],
                        'dur' => $m['durability'],
                    ],
                );
            }
            $count++;
        }
        $output->writeln(sprintf('Rebuilt compliance for %d systems.', $count));
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Functional test**

Создать несколько систем, вручную вставить мусорную строку в compliance-таблицу, вызвать команду через `CommandTester`, убедиться что мусор ушёл и валидные строки на месте.

- [ ] **Step 3: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Console/RebuildCoatingSystemComplianceCommandTest.php
```

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemComplianceCommand.php \
        app/tests/Functional/Coatings/Infrastructure/Console/RebuildCoatingSystemComplianceCommandTest.php
git commit -m "feat(coatings): add coatings:system-compliance:rebuild command"
```

---

## Task 10: DTOs + DTOTransformer

**Files:**
- Create: `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTO.php`
- Create: `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemLayerDTO.php`
- Create: `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php`
- Test: `app/tests/Unit/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformerTest.php`

**Interfaces:**
- Consumes: `CoatingSystem` entity.
- Produces: `CoatingSystemDTO` (плоский DTO для read-side + шаблоны). Поля: `id`, `title`, `description`, `substrate` (string enum-value), `substrateTitle`, `surfacePreparation` (плоские `grade/description/standard`), `layers: list<CoatingSystemLayerDTO>`, `totalDft`, `createdAt`, `updatedAt`, `compliance: list<array{standard, standardTitle, category, durability}>` (пусто если ещё не считался или запрос не запросил).

- [ ] **Step 1: DTO-структуры (dumb data-holders)**

```php
// CoatingSystemDTO.php — public поля, конструктор с named args.
```

```php
// CoatingSystemLayerDTO.php — public поля: id, position, dft, coatingId, coatingTitle, coatingBase, coatingBaseTitle, isZincRich.
```

- [ ] **Step 2: DTOTransformer с тестом**

Тест `CoatingSystemDTOTransformerTest.php`: собрать `CoatingSystem` в памяти, вызвать `CoatingSystemDTOTransformer::fromEntity($system)`, проверить точные значения полей.

- [ ] **Step 3: Реализовать transformer**

Простой mapping один-к-одному через геттеры.

- [ ] **Step 4: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Application/DTO/CoatingSystems
```

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Application/DTO/CoatingSystems \
        app/tests/Unit/Coatings/Application/DTO/CoatingSystems
git commit -m "feat(coatings): add CoatingSystem DTOs and transformer"
```

---

## Task 11: Commands: Create / UpdateMetadata / Remove

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Command/CreateCoatingSystem/{Command,CommandHandler,CommandResult}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/UpdateCoatingSystemMetadata/{Command,CommandHandler,CommandResult}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/RemoveCoatingSystem/{Command,CommandHandler,CommandResult}.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Command/{CreateCoatingSystem,UpdateCoatingSystemMetadata,RemoveCoatingSystem}Test.php`

**Interfaces produced:**
- `CreateCoatingSystemCommand(string $title, string $description, Substrate $substrate, SurfacePreparation $surfacePreparation, array<{coatingId: string, dft: int}> $initialLayers)`.
  Handler создаёт `CoatingSystem`, добавляет слои через `appendLayer`, сохраняет.
- `UpdateCoatingSystemMetadataCommand(string $id, string $title, string $description, Substrate $substrate, SurfacePreparation $surfacePreparation)`. Handler грузит агрегат, вызывает сеттеры, сохраняет.
- `RemoveCoatingSystemCommand(string $id)`. Handler удаляет.
- Все Result-типы содержат `?string $id` + list of errors (по образцу существующих commands).

Handler-ы имплементируют `App\Shared\Application\Command\CommandHandlerInterface` (см. `MEMORY.md` — регистрация через interface, не `#[AsMessageHandler]`).

- [ ] **Step 1: Написать функциональный тест `CreateCoatingSystemCommandHandler`**

Тест создаёт валидные Coating-ы в БД, отправляет команду через `CommandBusInterface`, проверяет что: (a) система появилась в БД, (b) слои идут по order, (c) индекс `coating_system_compliance` заполнен.

- [ ] **Step 2: Реализовать команду + handler**

```php
final readonly class CreateCoatingSystemCommand
{
    public function __construct(
        public string $title,
        public string $description,
        public Substrate $substrate,
        public SurfacePreparation $surfacePreparation,
        /** @var array<int, array{coatingId: string, dft: int}> */
        public array $initialLayers,
    ) {}
}

final class CreateCoatingSystemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CoatingSystemRepositoryInterface $repo,
        private readonly CoatingRepositoryInterface $coatingRepo,
        private readonly CoatingSystemChainValidator $chainValidator,
    ) {}

    public function __invoke(CreateCoatingSystemCommand $cmd): CreateCoatingSystemCommandResult
    {
        $system = new CoatingSystem(
            Uuid::v7(), $cmd->title, $cmd->description,
            $cmd->substrate, $cmd->surfacePreparation, $this->chainValidator,
        );
        foreach ($cmd->initialLayers as $layerData) {
            $coating = $this->coatingRepo->findById(Uuid::fromString($layerData['coatingId']));
            if (null === $coating) {
                throw new AppException(sprintf('Покрытие с id %s не найдено.', $layerData['coatingId']));
            }
            $system->appendLayer($coating, $layerData['dft']);
        }
        $this->repo->save($system);
        return new CreateCoatingSystemCommandResult($system->getId());
    }
}
```

Аналогично `UpdateCoatingSystemMetadataCommandHandler` и `RemoveCoatingSystemCommandHandler`.

- [ ] **Step 3: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Application/UseCase/Command
```

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Application/UseCase/Command/{CreateCoatingSystem,UpdateCoatingSystemMetadata,RemoveCoatingSystem} \
        app/tests/Functional/Coatings/Application/UseCase/Command
git commit -m "feat(coatings): add CoatingSystem create/update/remove commands"
```

---

## Task 12: Layer-мутирующие commands

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Command/AppendLayer/{Command,Handler,Result}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/InsertLayerAt/{Command,Handler,Result}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/RemoveLayerAt/{Command,Handler,Result}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/MoveLayer/{Command,Handler,Result}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/UpdateLayerDft/{Command,Handler,Result}.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Command/Layer/*.php`

**Interfaces:** каждый handler грузит `CoatingSystem` по `systemId`, вызывает соответствующий метод агрегата, сохраняет. Проекция compliance подхватится сама (postUpdate).

- [ ] **Step 1: Тесты — по одному на каждый handler**

Тест проверяет: (a) агрегат мутирован ожидаемо, (b) индекс compliance пересчитан.

- [ ] **Step 2: Реализация**

Handler по шаблону (пример AppendLayer):

```php
final class AppendLayerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CoatingSystemRepositoryInterface $repo,
        private readonly CoatingRepositoryInterface $coatingRepo,
    ) {}

    public function __invoke(AppendLayerCommand $cmd): AppendLayerCommandResult
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->systemId))
            ?? throw new AppException('Система покрытий не найдена.');
        $coating = $this->coatingRepo->findById(Uuid::fromString($cmd->coatingId))
            ?? throw new AppException('Покрытие не найдено.');
        $layer = $system->appendLayer($coating, $cmd->dft);
        $this->repo->save($system);
        return new AppendLayerCommandResult($layer->getId());
    }
}
```

- [ ] **Step 3: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Application/UseCase/Command/Layer
```

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Application/UseCase/Command/{AppendLayer,InsertLayerAt,RemoveLayerAt,MoveLayer,UpdateLayerDft} \
        app/tests/Functional/Coatings/Application/UseCase/Command/Layer
git commit -m "feat(coatings): add layer mutation commands for CoatingSystem"
```

---

## Task 13: Queries

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Query/FindCoatingSystemById/{Query,QueryHandler}.php`
- Create: `app/src/Coatings/Application/UseCase/Query/ListCoatingSystems/{Query,QueryHandler}.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance/{Query,QueryHandler}.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Query/*.php`

**Interfaces:**
- `FindCoatingSystemByIdQuery(string $id)` → `?CoatingSystemDTO`.
- `ListCoatingSystemsQuery(CoatingSystemsFilter $filter, int $page, int $perPage)` → `array{items: list<CoatingSystemDTO>, total: int}`.
- `SearchCoatingSystemsByComplianceQuery(ComplianceStandard $standard, string $category, string $durability, ?Substrate $substrate, int $page, int $perPage)` → `array{items: list<CoatingSystemDTO>, total: int}`.

Handler-ы имплементируют `App\Shared\Application\Query\QueryHandlerInterface`.

- [ ] **Step 1: Тесты**

Заполнить БД несколькими системами (разные substrate, разные compliance-профили), выполнить запрос через `QueryBusInterface`, проверить результат.

- [ ] **Step 2: Реализация**

Тонкие handler-ы, делегируют репозиторию + `CoatingSystemDTOTransformer`.

- [ ] **Step 3: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Application/UseCase/Query
```

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Application/UseCase/Query/{FindCoatingSystemById,ListCoatingSystems,SearchCoatingSystemsByCompliance} \
        app/tests/Functional/Coatings/Application/UseCase/Query
git commit -m "feat(coatings): add CoatingSystem queries"
```

---

## Task 14: Mapper (форма ↔ DTO/команда)

**Files:**
- Create: `app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php`
- Test: `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemMapperTest.php`

**Interfaces:**
- Methods:
  ```
  buildCommandFromInputData(array $input, ?string $systemId = null): CreateCoatingSystemCommand|UpdateCoatingSystemMetadataCommand
  buildInputDataFromDto(?CoatingSystemDTO $dto): array
  getValidationCollection(): Assert\Collection   // Symfony Validator constraints (только структурные — типы, длины)
  ```

Никаких `throw`-ов с бизнес-правилами. Только shape.

- [ ] **Step 1: Тест round-trip**

```php
public function test_round_trip(): void
{
    $input = [
        'title' => 'Test',
        'description' => '',
        'substrate' => 'steel_carbon',
        'surfacePreparation' => ['grade' => 'Sa 2 1/2', 'description' => '', 'standard' => 'ИСО 8501-1'],
        'layers' => [
            ['coatingId' => 'uuid-1', 'dft' => 60],
            ['coatingId' => 'uuid-2', 'dft' => 100],
        ],
    ];
    $cmd = $mapper->buildCommandFromInputData($input);
    $dto = /* сконструировать CoatingSystemDTO с теми же значениями */;
    self::assertSame($input, $mapper->buildInputDataFromDto($dto));
}
```

- [ ] **Step 2: Реализация**

- [ ] **Step 3: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemMapperTest.php
```

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php \
        app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemMapperTest.php
git commit -m "feat(coatings): add CoatingSystemMapper (form <-> DTO)"
```

---

## Task 15: Controllers (Cabinet UI): Add/Update/List/View/Remove

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/{AddAction,UpdateAction,ListAction,ViewAction,RemoveAction}.php`
- Create: `app/templates/cabinet/coating/coating_system/{list.html.twig,form.html.twig,view.html.twig}`
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/*.php`

**Interfaces:** Symfony controllers по образцу `Coatings/Infrastructure/Controller/Coating/AddAction.php`. Тонкие: читают POST, отдают в CommandBus, ловят `\Exception`, рендерят шаблон с `$error`.

**Routing:**
- `AddAction`    : GET/POST `/cabinet/coating/coating-system/add`
- `UpdateAction` : GET/POST `/cabinet/coating/coating-system/{id}/update`
- `ListAction`   : GET       `/cabinet/coating/coating-system/list`
- `ViewAction`   : GET       `/cabinet/coating/coating-system/{id}`
- `RemoveAction` : POST      `/cabinet/coating/coating-system/{id}/remove`

Auth — тот же гейт, что и для других Cabinet-страниц.

- [ ] **Step 1: Functional test для каждого action** (создание системы, редактирование, listing, view отображает compliance-бейджи, remove удаляет каскадно с индексом)

- [ ] **Step 2: Реализовать controllers по одному, коммитя каждый отдельно** (или одним commit-ом — по вкусу reviewer-а)

- [ ] **Step 3: Twig-шаблоны**

`list.html.twig` — таблица (title, substrate.title, layerCount, totalDft, updatedAt, actions).

`form.html.twig` — метаданные + список слоёв с `data-controller="coating-system-form"`.

`view.html.twig` — слева таблица слоёв, справа — плашки compliance, сгруппированные по standard.

- [ ] **Step 4: Пересобрать ассеты**

```bash
cd app && yarn dev
```

- [ ] **Step 5: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem
```

- [ ] **Step 6: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Controller/CoatingSystem \
        app/templates/cabinet/coating/coating_system \
        app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem
git commit -m "feat(coatings): add CoatingSystem cabinet controllers and templates"
```

---

## Task 16: Controller SearchByCompliance + API

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`
- Create: `app/templates/cabinet/coating/coating_system/search_by_compliance.html.twig`
- Create: `app/src/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceActionTest.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiActionTest.php`

**Interfaces:**
- Cabinet: `GET /cabinet/coating/coating-system/search-by-compliance?standard=ISO_12944&category=C3&durability=HIGH[&substrate=STEEL_CARBON]` → HTML со списком подходящих систем.
- API: `GET /api/coating-systems/by-compliance?standard=ISO_12944&category=C3&durability=HIGH` → JSON `{items: [...]}`.

- [ ] **Step 1: Тест cabinet + API**

- [ ] **Step 2: Реализация**

- [ ] **Step 3: Прогнать**

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceActionTest.php \
                            tests/Functional/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiActionTest.php
```

- [ ] **Step 4: Пересобрать ассеты**

```bash
cd app && yarn dev
```

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php \
        app/src/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php \
        app/templates/cabinet/coating/coating_system/search_by_compliance.html.twig \
        app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceActionTest.php \
        app/tests/Functional/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiActionTest.php
git commit -m "feat(coatings): add CoatingSystem search-by-compliance endpoints"
```

---

## Task 17: Stimulus controller для формы + навигация

**Files:**
- Create: `app/assets/controllers/coating_system_form_controller.js` — управление слоями (add/remove/move ↑↓, inline валидация DFT в диапазоне coating).
- Modify: `app/templates/base.html.twig` (или где меню) — добавить ссылку «Системы покрытий» в раздел Coatings.

**Interfaces:**
- Stimulus-контроллер регистрируется автоматически через `controllers.json` — просто положить файл в `app/assets/controllers/`.
- Data-attributes на форме: `data-controller="coating-system-form"`, targets `layers`, `layerTemplate`, action-кнопки `data-action="click->coating-system-form#addLayer"`.

- [ ] **Step 1: Реализовать контроллер (add/remove/move-up/move-down)**

- [ ] **Step 2: Обновить шаблон формы — использовать `<template>` для добавления новых строк слоёв**

- [ ] **Step 3: Ссылка в меню**

- [ ] **Step 4: Пересобрать ассеты**

```bash
cd app && yarn dev
```

- [ ] **Step 5: Ручная проверка в браузере** (skill-инструкцию `verify` не запускаем автоматически — reviewer при желании; но убедиться что форма не ломается).

- [ ] **Step 6: Коммит**

```bash
git add app/assets/controllers/coating_system_form_controller.js \
        app/templates/cabinet/coating/coating_system/form.html.twig \
        app/templates/base.html.twig
git commit -m "feat(coatings): add CoatingSystem form Stimulus controller and menu link"
```

---

## Task 18: Full-suite check и финальный полёт

- [ ] **Step 1: Прогнать все unit и functional Coatings**

```bash
cd app && vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
```

Ожидание: PASS.

- [ ] **Step 2: PHPStan**

```bash
cd app && vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
```

Ожидание: 0 errors.

- [ ] **Step 3: PHP-CS-Fixer dry-run**

```bash
cd app && vendor/bin/php-cs-fixer fix --dry-run --diff
```

Если есть diff — применить:

```bash
cd app && vendor/bin/php-cs-fixer fix
git add -u
git commit -m "chore(coatings): apply php-cs-fixer to CoatingSystem code"
```

- [ ] **Step 4: Проверить миграции с нуля**

```bash
cd app && bin/console doctrine:migrations:migrate first -n
cd app && bin/console doctrine:migrations:migrate -n
```

Ожидание: чистый apply без ошибок.

- [ ] **Step 5: Ручной smoke-тест в браузере** (`cd app && symfony server:start` или существующий workflow):
- Создать систему на 3 слоя (грунт Zn(R) EP → EP → PUR, суммарно ≥ 160 мкм на стали).
- Открыть view — увидеть compliance-плашку с несколькими парами.
- Открыть search-by-compliance с C3/HIGH — увидеть эту систему в результатах.

- [ ] **Step 6: Пересчитать индекс (для чистоты)**

```bash
cd app && bin/console coatings:system-compliance:rebuild
```

- [ ] **Step 7: Финальный коммит (если что-то поправлено ручными правками)**

---

## Self-Review

**Coverage vs spec (design.md):**
- Enum-ы (`Substrate`, `ComplianceStandard`, `PrimerType`, `IsoCorrosivityCategory`, `IsoDurability`) — Task 1. ✔
- `SurfacePreparation` VO — Task 2. ✔
- `Coating.isZincRich` — Task 3. ✔
- `CoatingSystem` + `CoatingSystemLayer` + `CoatingSystemChainValidator` — Task 4. ✔
- `ComplianceRule` + `ComplianceRuleBook` + `ComplianceEvaluator` — Task 5-6. ✔
- ORM + Repository + миграция таблиц — Task 7. ✔
- Compliance projector + миграция индекса — Task 8. ✔
- Rebuild-команда — Task 9. ✔
- DTOs + Transformer — Task 10. ✔
- Commands (Create/UpdateMetadata/Remove) — Task 11. ✔
- Layer commands (Append/InsertAt/RemoveAt/Move/UpdateDft) — Task 12. ✔
- Queries (FindById/List/SearchByCompliance) — Task 13. ✔
- Mapper — Task 14. ✔
- Cabinet controllers + Twig — Task 15. ✔
- SearchByCompliance controller + API — Task 16. ✔
- Stimulus controller + навигация — Task 17. ✔
- Full-suite check — Task 18. ✔

**Backlog (spec) — не входит в этот план (по договорённости):**
- Сертификаты.
- Snapshot vs live-ссылка Coating в слое.
- Полнотекстовый поиск.
- Интеграция с Proposals.
- Drag-n-drop (первая итерация — кнопки ↑↓).
- Полиморфизм по стандартам (если появится не-ISO стандарт).

**Type-consistency check:**
- `Coating::isZincRich()` (Task 3) используется в `ComplianceEvaluator` (Task 5) ✔
- `CoatingSystem::firstLayer()`, `followupLayers()`, `totalDft()`, `layerCount()`, `getLayers()` (Task 4) используются в `ComplianceEvaluator` (Task 5) и `Projector` (Task 8) ✔
- `SurfacePreparation::fromArray/toArray` (Task 2) используется в `SurfacePreparationType` (Task 7) ✔
- `ComplianceRuleBook::rules()` (Task 5-6) используется через `ComplianceEvaluatorFactory` (Task 8) ✔
- `CoatingSystemRepositoryInterface::findByCompliance` (Task 7) используется в `SearchCoatingSystemsByComplianceQueryHandler` (Task 13) ✔
- `Coating.dftRange->contains()` — `DftRange` есть в существующем коде; если сигнатура другая, поправить `CoatingSystemLayer::assertDftInCoatingRange` в Task 4.

**Placeholder scan:** ⚠ В Task 4 (`test_incompatible_neighbors_throws`) оставлен многоточие-заглушка тела теста, потому что конкретные пары `(base_i, base_i+1)` совместимости зависят от текущего содержимого `CoatingBase::allowedPrimers()`. Implementer должен сам выбрать пару, где `canBecoveredBy() === false`, из этой таблицы (например `ESI` поверх `AK` — если такой не найдётся, взять любую другую известную несовместимую).

Также в Task 6 полная таблица правил ISO 12944 (~80 записей) не расписана целиком в плане — implementer читает PDF ГОСТ 34667.5 (страницы 22-24 для B.2 и далее) и переносит. Тест `ComplianceRuleBookTest` покрывает по одной строке из каждой таблицы (B.2, B.3, B.4, B.5) как sanity-check.

---

## Execution Handoff

Plan complete and saved to `docs/plans/coating-system/plan.md`. Two execution options:

**1. Subagent-Driven (recommended)** — я диспатчу свежего subagent на каждую задачу, review между задачами, быстрая итерация.

**2. Inline Execution** — выполняем задачи в текущей сессии через executing-plans, batch execution с чекпоинтами.

Какой подход?
