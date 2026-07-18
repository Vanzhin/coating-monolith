# Chemical Resistance Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a chemical-resistance catalog to the app (substances × coatings × grades) and make the existing coating search return coatings by substance name or CAS number.

**Architecture:** New bounded context `ChemicalResistance` with three aggregates — `Substance`, `Assessment`, `Note` — linked by id (no ORM relations between aggregates, no ORM relations to `Coatings`). Search integrates by extending the existing `CoatingSearch.search_vector` (Postgres FTS) via a DB trigger that mixes canonical + aliases + CAS of all suitable-grade substances into the coating's vector. Русский — приоритетный canonical, английские/торговые/химические названия — в aliases.

**Tech Stack:** PHP 8.3, Symfony 7, Doctrine ORM (XML mapping), Postgres 16 with FTS + jsonb + `pg_trgm`, PHPUnit, Bootstrap 5 + Stimulus.

## Global Constraints

- Business invariants live in domain aggregates/VOs, not in mappers/handlers/controllers/twig — see project CLAUDE.md.
- User-facing error messages: Russian, via `AppException` (auto-mapped to HTTP 422). Technical detail — 4th arg `$log`.
- No custom code when a built-in fits (Doctrine JsonType, Bridge types, Symfony Validator). Extend built-ins; only write custom types where they clearly don't fit.
- Twig contains only markup — no `<script>` or `<style>` blocks. CSS in `app/assets/styles/`, JS in `app/assets/controllers/`. After changing JS/CSS: `cd app && yarn dev`.
- No `git commit`/`git add` unless the user explicitly asks.
- Migrations must be idempotent (`IF NOT EXISTS`, upsert semantics).
- Never leave `dd()`, `var_dump`, commented-out blocks. Remove `.DS_Store` from stage.
- Test structure mirrors `src/`: `tests/Unit/ChemicalResistance/...` mirrors `app/src/ChemicalResistance/...`.
- Functional tests hit real DB (Doctrine dislikes mocks).

---

## Task Overview

Phase 1 — Domain core (VOs, aggregates, specifications, unit-testable in isolation)
- Task 1: Grade enum + AssessmentTemperature + CasNumber
- Task 2: SubstanceNameNormalizer
- Task 3: Note aggregate + repository interface
- Task 4: Substance aggregate + specifications + repository interface
- Task 5: Assessment aggregate + specifications + repository interface
- Task 6: SystemNotes + NoteView + EffectiveAssessmentNotes

Phase 2 — Persistence
- Task 7: DBAL types (CasNumberType, StringCollectionType) + doctrine.yaml registration
- Task 8: DDL migration (three tables + indexes)
- Task 9: ORM XML mapping + Doctrine repository implementations
- Task 10: Round-trip persistence test

Phase 3 — Application (CRUD)
- Task 11: DTOs (SubstanceDTO, NoteDTO, AssessmentDTO, AssessmentRowDTO, SubstanceMatchDTO)
- Task 12: Note CRUD handlers
- Task 13: Substance CRUD handlers
- Task 14: Assessment CRUD handlers
- Task 15: SubstanceLookup service

Phase 4 — Import pipeline
- Task 16: GradeCellParser
- Task 17: DocxAssessmentParser + fixture-docx
- Task 18: ChemicalResistanceImporter
- Task 19: `bin/console coatings:chemical-resistance:import`

Phase 5 — Actual data seeding
- Task 20: **[claude does this]** Parse 3 real docx → JSON seed files (with hand-curated Russian canonical + CAS for top 300)

Phase 6 — FTS integration
- Task 21: SQL functions + triggers migration + integration test
- Task 22: Three seed migrations (batch-mode-safe)

Phase 7 — Read side for UI
- Task 23: ListCoatingAssessmentsQuery + handler
- Task 24: SubstanceAutocompleteQuery + handler
- Task 25: MatchSubstancesForSearchQuery + handler
- Task 26: Extend CoatingDTO + ListCoatingsQueryHandler with matchedSubstances

Phase 8 — UI: search results
- Task 27: List card — «✓ Стойкое к» badges
- Task 28: Modal — «Химическая стойкость» section (first page)
- Task 29: Partial endpoint + Stimulus for pagination/search inside modal
- Task 30: Deep-link `?substance=<uuid>` highlighting

Phase 9 — Admin UI
- Task 31: Substance admin (list/create/update/delete)
- Task 32: Note admin
- Task 33: Coating assessments page (inline edit)

Phase 10 — Ship
- Task 34: Final smoke test, cleanup, docs

---

## Phase 1 — Domain core

### Task 1: `Grade` enum, `AssessmentTemperature` VO, `CasNumber` VO

**Files:**
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Assessment/Grade.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Assessment/AssessmentTemperature.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Substance/CasNumber.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Aggregate/Assessment/GradeTest.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Aggregate/Assessment/AssessmentTemperatureTest.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Aggregate/Substance/CasNumberTest.php`

**Interfaces:**
- Produces:
  - `Grade::from(string): self`, `Grade::isSuitable(): bool`
  - `AssessmentTemperature::fromInt(int): self` (throws on 1..500 violation), `AssessmentTemperature::default(): self` (=40), `->celsius: int` (readonly public)
  - `CasNumber::fromString(string): self` (throws on format/checksum violation), `->value: string`, `->equals(self): bool`

- [ ] **Step 1: Write GradeTest**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use PHPUnit\Framework\TestCase;

final class GradeTest extends TestCase
{
    /** @dataProvider suitableCases */
    public function testIsSuitable(Grade $g, bool $expected): void
    {
        self::assertSame($expected, $g->isSuitable());
    }

    public static function suitableCases(): array
    {
        return [
            'R'  => [Grade::R,  true],
            'LR' => [Grade::LR, true],
            'NR' => [Grade::NR, false],
            'FS' => [Grade::FS, false],
            'NT' => [Grade::NT, false],
        ];
    }

    public function testFromStringUnknown(): void
    {
        $this->expectException(\ValueError::class);
        Grade::from('XX');
    }
}
```

- [ ] **Step 2: Run test — expect fail**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Aggregate/Assessment/GradeTest.php`
Expected: `class Grade not found`.

- [ ] **Step 3: Implement Grade**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment;

enum Grade: string
{
    case R = 'R';
    case NR = 'NR';
    case LR = 'LR';
    case FS = 'FS';
    case NT = 'NT';

    /** «Стойкое» для целей поиска и UI. Единственный источник правды. */
    public function isSuitable(): bool
    {
        return $this === self::R || $this === self::LR;
    }
}
```

- [ ] **Step 4: Run GradeTest — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Aggregate/Assessment/GradeTest.php`
Expected: PASS.

- [ ] **Step 5: Write AssessmentTemperatureTest**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class AssessmentTemperatureTest extends TestCase
{
    public function testDefaultIs40(): void
    {
        self::assertSame(40, AssessmentTemperature::default()->celsius);
    }

    public function testFromIntValid(): void
    {
        self::assertSame(70, AssessmentTemperature::fromInt(70)->celsius);
        self::assertSame(1, AssessmentTemperature::fromInt(1)->celsius);
        self::assertSame(500, AssessmentTemperature::fromInt(500)->celsius);
    }

    /** @dataProvider outOfRange */
    public function testFromIntOutOfRange(int $v): void
    {
        $this->expectException(AppException::class);
        AssessmentTemperature::fromInt($v);
    }

    public static function outOfRange(): array
    {
        return [[0], [-5], [501], [1000]];
    }
}
```

- [ ] **Step 6: Implement AssessmentTemperature**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class AssessmentTemperature
{
    private const MIN = 1;
    private const MAX = 500;
    private const DEFAULT = 40;

    private function __construct(public int $celsius) {}

    public static function fromInt(int $celsius): self
    {
        if ($celsius < self::MIN || $celsius > self::MAX) {
            throw new AppException(sprintf(
                'Температура %d °C вне допустимого диапазона %d..%d.',
                $celsius, self::MIN, self::MAX,
            ));
        }
        return new self($celsius);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }
}
```

- [ ] **Step 7: Run AssessmentTemperatureTest — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Aggregate/Assessment/AssessmentTemperatureTest.php`

- [ ] **Step 8: Write CasNumberTest**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class CasNumberTest extends TestCase
{
    /** @dataProvider validCases */
    public function testFromStringValid(string $input): void
    {
        $cas = CasNumber::fromString($input);
        self::assertSame($input, $cas->value);
        self::assertSame($input, (string)$cas);
    }

    /** Проверенные CAS чистых веществ + checksum. */
    public static function validCases(): array
    {
        return [
            'water'       => ['7732-18-5'],
            'ethanol'     => ['64-17-5'],
            'methanol'    => ['67-56-1'],
            'acetone'     => ['67-64-1'],
            'ethylene-glycol' => ['107-21-1'],
            'toluene'     => ['108-88-3'],
            'formaldehyde'=> ['50-00-0'],
        ];
    }

    /** @dataProvider invalidCases */
    public function testFromStringInvalid(string $input): void
    {
        $this->expectException(AppException::class);
        CasNumber::fromString($input);
    }

    public static function invalidCases(): array
    {
        return [
            'wrong-checksum'      => ['107-21-2'],
            'letters'             => ['abc-de-f'],
            'too-short-left'      => ['7-18-5'],
            'too-long-left'       => ['12345678-18-5'],
            'wrong-format'        => ['107215'],
            'empty'               => [''],
            'no-dashes'           => ['107215'],
            'extra-spaces-inside' => ['107 - 21 - 1'],
        ];
    }

    public function testEquals(): void
    {
        self::assertTrue(CasNumber::fromString('7732-18-5')->equals(CasNumber::fromString('7732-18-5')));
        self::assertFalse(CasNumber::fromString('7732-18-5')->equals(CasNumber::fromString('64-17-5')));
    }
}
```

- [ ] **Step 9: Implement CasNumber**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class CasNumber implements \Stringable
{
    private function __construct(public string $value) {}

    public static function fromString(string $raw): self
    {
        if (!preg_match('/^(\d{2,7})-(\d{2})-(\d)$/', $raw, $m)) {
            throw new AppException(sprintf(
                'Неверный формат CAS-номера «%s». Ожидается NNNNNNN-NN-N.',
                $raw,
            ));
        }
        [, $left, $mid, $checkDigit] = $m;
        $digits = str_split($left . $mid);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $d) {
            $sum += (int)$d * ($i + 1);
        }
        if ($sum % 10 !== (int)$checkDigit) {
            throw new AppException(sprintf(
                'Неверная контрольная цифра CAS-номера «%s».',
                $raw,
            ));
        }
        return new self($raw);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 10: Run all three tests — expect all green**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/`

- [ ] **Step 11: Stop for review** — do not commit; the reviewer may combine phase-1 tasks into one commit later.

---

### Task 2: `SubstanceNameNormalizer`

**Files:**
- Create: `app/src/ChemicalResistance/Domain/Service/SubstanceNameNormalizer.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Service/SubstanceNameNormalizerTest.php`

**Interfaces:**
- Produces: `SubstanceNameNormalizer::normalize(string): string` (static)

**Purpose:** Single source of truth for comparing substance names as duplicates. Used by `Substance` (canonical_name_key), by `Substance::hasName`, and by `SubstanceLookup` in the importer. Wrong here — desynced dedup across all three.

- [ ] **Step 1: Write test with realistic cases from the docx files**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use PHPUnit\Framework\TestCase;

final class SubstanceNameNormalizerTest extends TestCase
{
    /** @dataProvider sameGroup */
    public function testAllInGroupNormalizeSame(array $variants): void
    {
        $first = SubstanceNameNormalizer::normalize($variants[0]);
        foreach ($variants as $v) {
            self::assertSame($first, SubstanceNameNormalizer::normalize($v), "«$v» expected to match «{$variants[0]}»");
        }
    }

    public static function sameGroup(): array
    {
        return [
            'ethanediol synonyms' => [[
                '1,2-Ethanediol',
                '1,2 - Ethanediol',
                '1,2-ETHANEDIOL',
                '1,2-Etandiol (N)',
                '1,2-Dihydroxyethane',  // NB: different word, but rule below still lets it be different — see distinct group
            ]],
            'butanone synonyms'   => [[
                '2-Butanone',
                '2-Butanon (N)',
                '2-Butanone (*Shell)',
            ]],
            'trademark stripping' => [[
                '00813 Marine Diesel Blend* (*™ Famm)',
                '00813 Marine Diesel Blend',
            ]],
        ];
    }

    /** @dataProvider distinctGroup */
    public function testDifferentSubstancesNormalizeDifferently(string $a, string $b): void
    {
        self::assertNotSame(
            SubstanceNameNormalizer::normalize($a),
            SubstanceNameNormalizer::normalize($b),
            "«$a» and «$b» must not be normalized to the same key",
        );
    }

    public static function distinctGroup(): array
    {
        return [
            'ethanediol vs dihydroxyethane' => ['1,2-Ethanediol', '1,2-Dihydroxyethane'],
            'butanone vs methylpropylketone' => ['2-Butanone', '2-methylpropylmethylketone'],
            'water vs waters' => ['Water', 'Waters'],
            'russian vs english' => ['Вода', 'Water'],
        ];
    }
}
```

Note: two versions of the "ethanediol" case above look contradictory. The point of the first (sameGroup) is that trademark/lang markers and whitespace collapse; the point of distinct is that different chemical names remain distinct even if their meaning is the same substance — those are merged later by admin/importer through `Substance.aliases`, not by the normalizer. Delete the `1,2-Dihydroxyethane` line from `sameGroup` if it fails; that's expected behavior.

Fix: remove the `1,2-Dihydroxyethane` entry from the sameGroup ethanediol list before running.

- [ ] **Step 2: Run test — expect fail (class missing)**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Service/SubstanceNameNormalizerTest.php`

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

/**
 * Единственный источник правды для сравнения названий веществ как дубликатов.
 * Используется:
 *  - Substance::canonicalNameKey (UNIQUE в БД);
 *  - Substance::hasName / addAlias (проверка внутри агрегата);
 *  - SubstanceLookup при импорте (findOrCreateByName).
 */
final class SubstanceNameNormalizer
{
    public static function normalize(string $raw): string
    {
        $s = \Normalizer::normalize($raw, \Normalizer::FORM_KC) ?: $raw;
        $s = mb_strtolower($s, 'UTF-8');
        // языковые маркеры (N)/(G)/(n)/(g) — норвежское/немецкое написание
        $s = preg_replace('/\([ng]\)/u', '', $s) ?? $s;
        // торговые пометки: *Shell, *TRADENAME Exxon, *™ Famm — всё что окружено *
        $s = preg_replace('/\*[^\s,()*]*\*?/u', '', $s) ?? $s;
        // технические разделители — пробелы/тире/точки/запятые/слэши
        $s = preg_replace('/[\s\-.,;\/\\\\]+/u', '', $s) ?? $s;
        return trim($s);
    }
}
```

- [ ] **Step 4: Run — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Service/SubstanceNameNormalizerTest.php`

- [ ] **Step 5: Stop for review.**

---

### Task 3: `Note` aggregate + repository interface

**Files:**
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Note/Note.php`
- Create: `app/src/ChemicalResistance/Domain/Repository/NoteRepository.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Aggregate/Note/NoteTest.php`

**Interfaces:**
- Consumes: `App\Shared\Domain\Aggregate\Aggregate`, `App\Shared\Domain\Service\AssertService`, `App\Shared\Infrastructure\Exception\AppException`
- Produces:
  - `Note::__construct(Uuid $id, string $title, string $description)`
  - `Note::getId(): string` (rfc4122), `->getTitle(): string`, `->getDescription(): string`
  - `Note::setTitle(string): void` (maxLength 200), `->setDescription(string): void` (maxLength 2000)
  - `NoteRepository` interface: `save(Note): void`, `remove(Note): void`, `find(Uuid): ?Note`, `findAllByIds(array $ids): array` (accepts string UUIDs; returns list<Note> in same order as input, missing ids dropped)

- [ ] **Step 1: Write NoteTest**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Note;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NoteTest extends TestCase
{
    public function testConstruct(): void
    {
        $id = Uuid::v4();
        $n = new Note($id, 'Изменение цвета покрытия', 'Покрытие может поменять цвет…');
        self::assertSame($id->toRfc4122(), $n->getId());
        self::assertSame('Изменение цвета покрытия', $n->getTitle());
    }

    public function testTitleTooLong(): void
    {
        $this->expectException(AppException::class);
        new Note(Uuid::v4(), str_repeat('a', 201), 'desc');
    }

    public function testDescriptionTooLong(): void
    {
        $this->expectException(AppException::class);
        new Note(Uuid::v4(), 'title', str_repeat('a', 2001));
    }
}
```

- [ ] **Step 2: Run — expect fail (missing class)**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Aggregate/Note/`

- [ ] **Step 3: Implement Note aggregate**

Follow the same style as existing aggregates in `app/src/Coatings/Domain/Aggregate/Coating/`. Use `AssertService::maxLength()` for length checks (already throws `AppException`).

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Note;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use Symfony\Component\Uid\Uuid;

class Note extends Aggregate
{
    public readonly Uuid $id;
    private string $title;
    private string $description;

    public function __construct(Uuid $id, string $title, string $description)
    {
        $this->id = $id;
        $this->setTitle($title);
        $this->setDescription($description);
    }

    public function getId(): string { return $this->id->toRfc4122(); }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }

    public function setTitle(string $title): void
    {
        AssertService::maxLength($title, 200);
        $this->title = $title;
    }

    public function setDescription(string $description): void
    {
        AssertService::maxLength($description, 2000);
        $this->description = $description;
    }
}
```

- [ ] **Step 4: Implement `NoteRepository` interface**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Repository;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use Symfony\Component\Uid\Uuid;

interface NoteRepository
{
    public function save(Note $note): void;
    public function remove(Note $note): void;
    public function find(Uuid $id): ?Note;

    /**
     * @param list<string> $ids UUIDs as strings
     * @return list<Note>       ordered as $ids; missing ids silently skipped
     */
    public function findAllByIds(array $ids): array;
}
```

- [ ] **Step 5: Run tests — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Aggregate/Note/`

- [ ] **Step 6: Stop for review.**

---

### Task 4: `Substance` aggregate + specifications + repository interface

**Files:**
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Substance/Substance.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Substance/Specification/SubstanceSpecification.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Substance/Specification/UniqueSubstanceNameSpecification.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Substance/Specification/UniqueCasSpecification.php`
- Create: `app/src/ChemicalResistance/Domain/Repository/SubstanceRepository.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Aggregate/Substance/SubstanceTest.php`

**Interfaces:**
- Consumes: `SubstanceNameNormalizer`, `CasNumber`, `StringCollection`, `AppException`.
- Produces:
  - `Substance::__construct(Uuid $id, string $canonicalName, ?CasNumber $cas, StringCollection $aliases, SubstanceSpecification $spec)` — see existing pattern in `Coating` which takes `CoatingSpecification` (a bundle of unique-title specs) as one constructor arg.
  - Getters: `getId(): string`, `getCanonicalName(): string`, `getCanonicalNameKey(): string`, `getCas(): ?CasNumber`, `getAliases(): StringCollection`
  - Setters: `setCanonicalName(string)`, `setCas(?CasNumber)`, `addAlias(string)`, `removeAlias(string)`
  - `hasName(string $probe): bool` — true if normalized probe equals normalized canonical or any alias
  - `getSearchableNames(): StringCollection` — canonical + all aliases + optional cas (for FTS)
  - `SubstanceSpecification` — bag with `->uniqueName: UniqueSubstanceNameSpecification` and `->uniqueCas: UniqueCasSpecification` (mirror `CoatingSpecification` in `app/src/Coatings/Domain/Aggregate/Coating/Specification/CoatingSpecification.php`).
  - `SubstanceRepository`:
    - `save(Substance): void`, `remove(Substance): void`
    - `find(Uuid): ?Substance`
    - `findByCanonicalNameKey(string $key): ?Substance` — for `UniqueSubstanceNameSpecification` and `SubstanceLookup`
    - `findByCas(CasNumber): ?Substance` — for `UniqueCasSpecification`
    - `findAllByIds(array $ids): array`

- [ ] **Step 1: Write `SubstanceTest` with the key invariants**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueCasSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueSubstanceNameSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SubstanceTest extends TestCase
{
    private function noopSpec(): SubstanceSpecification
    {
        $repo = $this->createMock(SubstanceRepository::class);
        $repo->method('findByCanonicalNameKey')->willReturn(null);
        $repo->method('findByCas')->willReturn(null);
        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($repo),
            new UniqueCasSpecification($repo),
        );
    }

    public function testConstructRussianCanonical(): void
    {
        $s = new Substance(Uuid::v4(), 'Этиленгликоль', CasNumber::fromString('107-21-1'),
            new StringCollection('Ethylene glycol', '1,2-Ethanediol'), $this->noopSpec());
        self::assertSame('Этиленгликоль', $s->getCanonicalName());
        self::assertSame('107-21-1', (string)$s->getCas());
        self::assertSame(['Ethylene glycol', '1,2-Ethanediol'], $s->getAliases()->getList());
    }

    public function testHasNameFindsCanonical(): void
    {
        $s = new Substance(Uuid::v4(), 'Этиленгликоль', null, new StringCollection(), $this->noopSpec());
        self::assertTrue($s->hasName('этиленгликоль'));
        self::assertTrue($s->hasName(' Этиленгликоль '));
        self::assertFalse($s->hasName('Водоглицерин'));
    }

    public function testHasNameFindsAliases(): void
    {
        $s = new Substance(Uuid::v4(), 'Этиленгликоль', null,
            new StringCollection('Ethylene glycol'), $this->noopSpec());
        self::assertTrue($s->hasName('ethylene-glycol'));
        self::assertTrue($s->hasName('ETHYLENE GLYCOL'));
    }

    public function testAddAliasIdempotent(): void
    {
        $s = new Substance(Uuid::v4(), 'Water', null,
            new StringCollection('Вода'), $this->noopSpec());
        $s->addAlias('Вода');        // same
        $s->addAlias(' вода ');       // normalizes to same
        self::assertSame(['Вода'], $s->getAliases()->getList());
    }

    public function testAddAliasSameAsCanonicalIsNoop(): void
    {
        $s = new Substance(Uuid::v4(), 'Water', null, new StringCollection(), $this->noopSpec());
        $s->addAlias('WATER');
        self::assertSame([], $s->getAliases()->getList());
    }

    public function testCanonicalNameKeyReflectsRename(): void
    {
        $s = new Substance(Uuid::v4(), 'Water', null, new StringCollection(), $this->noopSpec());
        $keyBefore = $s->getCanonicalNameKey();
        $s->setCanonicalName('Вода');
        self::assertNotSame($keyBefore, $s->getCanonicalNameKey());
    }
}
```

- [ ] **Step 2: Run — expect fail**

- [ ] **Step 3: Implement `SubstanceSpecification` (bag), `UniqueSubstanceNameSpecification`, `UniqueCasSpecification`**

Mirror the existing pattern in `app/src/Coatings/Domain/Aggregate/Coating/Specification/CoatingSpecification.php` and `UniqueTitleCoatingSpecification.php`. Key rules:

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance\Specification;

use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Infrastructure\Exception\AppException;

final class UniqueSubstanceNameSpecification
{
    public function __construct(private SubstanceRepository $repo) {}

    public function satisfy(Substance $s): void
    {
        $existing = $this->repo->findByCanonicalNameKey($s->getCanonicalNameKey());
        if ($existing !== null && $existing->getId() !== $s->getId()) {
            throw new AppException(sprintf(
                'Вещество «%s» уже существует в справочнике.',
                $s->getCanonicalName(),
            ));
        }
    }
}
```

Same pattern for `UniqueCasSpecification` (skip if `getCas() === null`).

Bag:
```php
final readonly class SubstanceSpecification
{
    public function __construct(
        public UniqueSubstanceNameSpecification $uniqueName,
        public UniqueCasSpecification $uniqueCas,
    ) {}
}
```

- [ ] **Step 4: Implement `SubstanceRepository` interface**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Repository;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use Symfony\Component\Uid\Uuid;

interface SubstanceRepository
{
    public function save(Substance $s): void;
    public function remove(Substance $s): void;
    public function find(Uuid $id): ?Substance;
    public function findByCanonicalNameKey(string $key): ?Substance;
    public function findByCas(CasNumber $cas): ?Substance;

    /**
     * @param list<string> $ids
     * @return list<Substance>
     */
    public function findAllByIds(array $ids): array;
}
```

- [ ] **Step 5: Implement `Substance` aggregate**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

class Substance extends Aggregate
{
    public readonly Uuid $id;
    private string $canonicalName;
    private string $canonicalNameKey;
    private ?CasNumber $cas = null;
    /** @var list<string> — hydrated from JSONB via StringCollectionType */
    private StringCollection $aliases;
    private SubstanceSpecification $specification;

    public function __construct(
        Uuid $id,
        string $canonicalName,
        ?CasNumber $cas,
        StringCollection $aliases,
        SubstanceSpecification $specification,
    ) {
        $this->id = $id;
        $this->specification = $specification;
        $this->aliases = new StringCollection();     // will be replaced by addAlias() loop
        $this->setCanonicalName($canonicalName);
        $this->setCas($cas);
        foreach ($aliases as $a) {
            $this->addAlias($a);
        }
    }

    public function getId(): string { return $this->id->toRfc4122(); }
    public function getCanonicalName(): string { return $this->canonicalName; }
    public function getCanonicalNameKey(): string { return $this->canonicalNameKey; }
    public function getCas(): ?CasNumber { return $this->cas; }
    public function getAliases(): StringCollection { return $this->aliases; }

    public function setCanonicalName(string $name): void
    {
        $name = trim($name);
        AssertService::maxLength($name, 200);
        if ($name === '') {
            throw new AppException('Название вещества не может быть пустым.');
        }
        $this->canonicalName = $name;
        $this->canonicalNameKey = SubstanceNameNormalizer::normalize($name);
        $this->specification->uniqueName->satisfy($this);
    }

    public function setCas(?CasNumber $cas): void
    {
        $this->cas = $cas;
        $this->specification->uniqueCas->satisfy($this);
    }

    public function addAlias(string $alias): void
    {
        $alias = trim($alias);
        if ($alias === '') { return; }
        AssertService::maxLength($alias, 200);
        $key = SubstanceNameNormalizer::normalize($alias);
        if ($key === $this->canonicalNameKey) { return; }
        foreach ($this->aliases as $existing) {
            if (SubstanceNameNormalizer::normalize($existing) === $key) { return; }
        }
        $this->aliases = new StringCollection(...$this->aliases->getList(), ...[$alias]);
    }

    public function removeAlias(string $alias): void
    {
        $key = SubstanceNameNormalizer::normalize($alias);
        $kept = array_values(array_filter(
            $this->aliases->getList(),
            fn(string $a) => SubstanceNameNormalizer::normalize($a) !== $key,
        ));
        $this->aliases = new StringCollection(...$kept);
    }

    public function hasName(string $probe): bool
    {
        $key = SubstanceNameNormalizer::normalize($probe);
        if ($key === $this->canonicalNameKey) { return true; }
        foreach ($this->aliases as $a) {
            if (SubstanceNameNormalizer::normalize($a) === $key) { return true; }
        }
        return false;
    }

    /** canonical + aliases + optional CAS — то, что попадёт в FTS-вектор coating. */
    public function getSearchableNames(): StringCollection
    {
        $items = [$this->canonicalName, ...$this->aliases->getList()];
        if ($this->cas !== null) { $items[] = $this->cas->value; }
        return new StringCollection(...$items);
    }
}
```

- [ ] **Step 6: Run — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Aggregate/Substance/`

- [ ] **Step 7: Stop for review.**

---

### Task 5: `Assessment` aggregate + specifications + repository interface

**Files:**
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Assessment/Assessment.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Assessment/Specification/AssessmentSpecification.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Assessment/Specification/UniqueCoatingSubstanceAssessmentSpecification.php`
- Create: `app/src/ChemicalResistance/Domain/Aggregate/Assessment/Specification/AssessmentNotesConsistencyValidator.php`
- Create: `app/src/ChemicalResistance/Domain/Repository/AssessmentRepository.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Aggregate/Assessment/AssessmentTest.php`

**Interfaces:**
- Consumes: `Grade`, `AssessmentTemperature`, `StringCollection`, `NoteRepository`, `AssessmentRepository`.
- Produces:
  - `Assessment::__construct(Uuid $id, Uuid $coatingId, Uuid $substanceId, Grade $grade, ?AssessmentTemperature $maxTemp, StringCollection $noteIds, AssessmentSpecification $spec, NoteRepository $notesForConsistency)`
    - If `$maxTemp === null` → uses `AssessmentTemperature::default()`.
  - Getters as expected.
  - `setGrade`, `setMaxTemperature`, `setNoteIds(StringCollection, NoteRepository)`.
  - `AssessmentSpecification` bag: `->uniqueCoatingSubstance`, `->notesConsistency`.
  - `AssessmentRepository`:
    - `save(Assessment): void`, `remove(Assessment): void`
    - `find(Uuid): ?Assessment`
    - `findByCoatingAndSubstance(Uuid $coatingId, Uuid $substanceId): ?Assessment`
    - `findAllByCoating(Uuid $coatingId): list<Assessment>`
    - `paginateByCoating(Uuid $coatingId, ?string $search, int $page, int $pageSize): PaginationResult` — used by ListCoatingAssessments read side; may be added in Task 23 if not needed here.

- [ ] **Step 1: Write AssessmentTest**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentNotesConsistencyValidator;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\UniqueCoatingSubstanceAssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AssessmentTest extends TestCase
{
    private function spec(): AssessmentSpecification
    {
        $repo = $this->createMock(AssessmentRepository::class);
        $repo->method('findByCoatingAndSubstance')->willReturn(null);
        return new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($repo),
            new AssessmentNotesConsistencyValidator(),
        );
    }

    private function notesRepoWith(array $notes): NoteRepository
    {
        $r = $this->createMock(NoteRepository::class);
        $r->method('findAllByIds')->willReturn($notes);
        return $r;
    }

    public function testDefaultMaxTempIs40(): void
    {
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection(),
            $this->spec(), $this->notesRepoWith([]),
        );
        self::assertSame(40, $a->getMaxTemperature()->celsius);
    }

    public function testExplicitMaxTemp(): void
    {
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, AssessmentTemperature::fromInt(70),
            new StringCollection(),
            $this->spec(), $this->notesRepoWith([]),
        );
        self::assertSame(70, $a->getMaxTemperature()->celsius);
    }

    public function testNoteIdsMustExist(): void
    {
        $noteId = Uuid::v4()->toRfc4122();
        // Note repo returns empty — id does not exist.
        $this->expectException(AppException::class);
        new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection($noteId),
            $this->spec(), $this->notesRepoWith([]),
        );
    }

    public function testNoteIdsSuccess(): void
    {
        $noteId = Uuid::v4();
        $note = new Note($noteId, 'T', 'D');
        $a = new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null, new StringCollection($noteId->toRfc4122()),
            $this->spec(), $this->notesRepoWith([$note]),
        );
        self::assertSame([$noteId->toRfc4122()], $a->getNoteIds()->getList());
    }

    public function testNoteIdsRejectsDuplicates(): void
    {
        $noteId = Uuid::v4();
        $note = new Note($noteId, 'T', 'D');
        $this->expectException(AppException::class);
        new Assessment(
            Uuid::v4(), Uuid::v4(), Uuid::v4(),
            Grade::R, null,
            new StringCollection($noteId->toRfc4122(), $noteId->toRfc4122()),
            $this->spec(), $this->notesRepoWith([$note]),
        );
    }
}
```

- [ ] **Step 2: Run — expect fail**

- [ ] **Step 3: Implement `AssessmentNotesConsistencyValidator`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment\Specification;

use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;

final class AssessmentNotesConsistencyValidator
{
    public function validate(StringCollection $noteIds, NoteRepository $notes): void
    {
        $ids = $noteIds->getList();
        if (count($ids) !== count(array_unique($ids))) {
            throw new AppException('Список примечаний содержит дубли.');
        }
        $found = $notes->findAllByIds($ids);
        if (count($found) !== count($ids)) {
            throw new AppException('Один или несколько идентификаторов примечаний не найдены в справочнике.');
        }
    }
}
```

- [ ] **Step 4: Implement `UniqueCoatingSubstanceAssessmentSpecification`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment\Specification;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\Shared\Infrastructure\Exception\AppException;

final class UniqueCoatingSubstanceAssessmentSpecification
{
    public function __construct(private AssessmentRepository $repo) {}

    public function satisfy(Assessment $a): void
    {
        $existing = $this->repo->findByCoatingAndSubstance($a->getCoatingId(), $a->getSubstanceId());
        if ($existing !== null && $existing->getId() !== $a->getId()) {
            throw new AppException('Оценка для этой пары «покрытие — вещество» уже существует.');
        }
    }
}
```

Bag:
```php
final readonly class AssessmentSpecification
{
    public function __construct(
        public UniqueCoatingSubstanceAssessmentSpecification $uniqueCoatingSubstance,
        public AssessmentNotesConsistencyValidator $notesConsistency,
    ) {}
}
```

- [ ] **Step 5: Implement `AssessmentRepository` interface**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Repository;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\Shared\Domain\Repository\PaginationResult;
use Symfony\Component\Uid\Uuid;

interface AssessmentRepository
{
    public function save(Assessment $a): void;
    public function remove(Assessment $a): void;
    public function find(Uuid $id): ?Assessment;
    public function findByCoatingAndSubstance(Uuid $coatingId, Uuid $substanceId): ?Assessment;
    /** @return list<Assessment> */
    public function findAllByCoating(Uuid $coatingId): array;
    /** @return list<Assessment> */
    public function findAllBySubstance(Uuid $substanceId): array;
    public function paginateByCoating(Uuid $coatingId, ?string $search, int $page, int $pageSize): PaginationResult;
}
```

- [ ] **Step 6: Implement `Assessment` aggregate**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

class Assessment extends Aggregate
{
    public readonly Uuid $id;
    private Uuid $coatingId;
    private Uuid $substanceId;
    private Grade $grade;
    private AssessmentTemperature $maxTemperature;
    private StringCollection $noteIds;
    private AssessmentSpecification $specification;

    public function __construct(
        Uuid $id,
        Uuid $coatingId,
        Uuid $substanceId,
        Grade $grade,
        ?AssessmentTemperature $maxTemperature,
        StringCollection $noteIds,
        AssessmentSpecification $specification,
        NoteRepository $notesForConsistency,
    ) {
        $this->id = $id;
        $this->coatingId = $coatingId;
        $this->substanceId = $substanceId;
        $this->specification = $specification;

        $this->grade = $grade;
        $this->maxTemperature = $maxTemperature ?? AssessmentTemperature::default();
        $this->setNoteIds($noteIds, $notesForConsistency);
        $this->specification->uniqueCoatingSubstance->satisfy($this);
    }

    public function getId(): string { return $this->id->toRfc4122(); }
    public function getCoatingId(): Uuid { return $this->coatingId; }
    public function getSubstanceId(): Uuid { return $this->substanceId; }
    public function getGrade(): Grade { return $this->grade; }
    public function getMaxTemperature(): AssessmentTemperature { return $this->maxTemperature; }
    public function getNoteIds(): StringCollection { return $this->noteIds; }

    public function setGrade(Grade $g): void { $this->grade = $g; }
    public function setMaxTemperature(AssessmentTemperature $t): void { $this->maxTemperature = $t; }

    public function setNoteIds(StringCollection $ids, NoteRepository $notes): void
    {
        $this->specification->notesConsistency->validate($ids, $notes);
        $this->noteIds = $ids;
    }
}
```

- [ ] **Step 7: Run — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Aggregate/Assessment/`

- [ ] **Step 8: Stop for review.**

---

### Task 6: `SystemNotes`, `NoteView`, `EffectiveAssessmentNotes`

**Files:**
- Create: `app/src/ChemicalResistance/Domain/Service/SystemNote.php`
- Create: `app/src/ChemicalResistance/Domain/Service/SystemNotes.php`
- Create: `app/src/ChemicalResistance/Domain/Service/NoteView.php`
- Create: `app/src/ChemicalResistance/Domain/Service/EffectiveAssessmentNotes.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Service/EffectiveAssessmentNotesTest.php`
- Test: `app/tests/Unit/ChemicalResistance/Domain/Service/SystemNotesTest.php`

**Interfaces:**
- Produces:
  - `SystemNote(string $title, string $description)` — final readonly
  - `SystemNotes::all(): list<SystemNote>` — static
  - `NoteView::system(SystemNote): self`, `NoteView::stored(Note): self` — with `->title, ->description, ->isSystem: bool`
  - `EffectiveAssessmentNotes::__construct(NoteRepository)`; `->of(Assessment): list<NoteView>` — returns SystemNotes first, then stored notes in `noteIds` order

- [ ] **Step 1: Write SystemNotesTest**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Service\SystemNotes;
use PHPUnit\Framework\TestCase;

final class SystemNotesTest extends TestCase
{
    public function testContainsHighViscosityRule(): void
    {
        $notes = SystemNotes::all();
        self::assertNotEmpty($notes);
        $found = false;
        foreach ($notes as $n) {
            if (str_contains($n->description, '+70')) { $found = true; break; }
        }
        self::assertTrue($found, 'System notes must contain the "+70°C for solids" rule from legend.');
    }
}
```

- [ ] **Step 2: Implement `SystemNote` + `SystemNotes`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

final readonly class SystemNote
{
    public function __construct(public string $title, public string $description) {}
}
```

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

final class SystemNotes
{
    /** @return list<SystemNote> */
    public static function all(): array
    {
        return [
            new SystemNote(
                'Высоковязкие и твёрдые вещества',
                'При этом высоковязкие и твёрдые вещества могут храниться в постоянном контакте с ЛКП с температурой до +70°C, если нет отдельных примечаний.',
            ),
        ];
    }
}
```

- [ ] **Step 3: Write `EffectiveAssessmentNotesTest`**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\ChemicalResistance\Domain\Service\EffectiveAssessmentNotes;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EffectiveAssessmentNotesTest extends TestCase
{
    public function testSystemNotesFirstThenStoredInOrder(): void
    {
        $n1 = new Note(Uuid::v4(), 'Прим. 1', 'text1');
        $n2 = new Note(Uuid::v4(), 'Прим. 4', 'text4');

        $notes = $this->createMock(NoteRepository::class);
        $notes->method('findAllByIds')
            ->with([$n1->getId(), $n2->getId()])
            ->willReturn([$n1, $n2]);

        $a = $this->createMock(Assessment::class);
        $a->method('getNoteIds')->willReturn(new StringCollection($n1->getId(), $n2->getId()));

        $resolver = new EffectiveAssessmentNotes($notes);
        $views = $resolver->of($a);

        self::assertGreaterThanOrEqual(2, count($views));
        self::assertTrue($views[0]->isSystem);
        // Last two must be stored, in noteIds order.
        self::assertFalse($views[count($views)-2]->isSystem);
        self::assertSame('Прим. 1', $views[count($views)-2]->title);
        self::assertSame('Прим. 4', $views[count($views)-1]->title);
    }
}
```

- [ ] **Step 4: Implement `NoteView`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;

final readonly class NoteView
{
    private function __construct(public string $title, public string $description, public bool $isSystem) {}

    public static function system(SystemNote $n): self { return new self($n->title, $n->description, true); }
    public static function stored(Note $n): self { return new self($n->getTitle(), $n->getDescription(), false); }
}
```

- [ ] **Step 5: Implement `EffectiveAssessmentNotes`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Repository\NoteRepository;

final class EffectiveAssessmentNotes
{
    public function __construct(private NoteRepository $notes) {}

    /** @return list<NoteView> */
    public function of(Assessment $a): array
    {
        $stored = $this->notes->findAllByIds($a->getNoteIds()->getList());
        $out = array_map(fn(SystemNote $n) => NoteView::system($n), SystemNotes::all());
        foreach ($stored as $n) { $out[] = NoteView::stored($n); }
        return $out;
    }
}
```

- [ ] **Step 6: Run — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Domain/Service/`

- [ ] **Step 7: Stop for review — end of Phase 1.**

---

## Phase 2 — Persistence

### Task 7: DBAL types + doctrine.yaml registration

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Database/DBAL/CasNumberType.php`
- Create: `app/src/Shared/Infrastructure/Database/DBAL/StringCollectionType.php`
- Modify: `app/config/packages/doctrine.yaml` — add two `types:` entries and one `mappings:` entry for `ChemicalResistance`.
- Test: `app/tests/Unit/ChemicalResistance/Infrastructure/Database/DBAL/CasNumberTypeTest.php`

**Interfaces:**
- Consumes: `CasNumber`, `StringCollection`.
- Produces:
  - `CasNumberType` — Doctrine type with `getName(): 'cas_number'`, VARCHAR(15) column, `convertToPHPValue → ?CasNumber`, `convertToDatabaseValue → ?string`.
  - `StringCollectionType` — extends `JsonType`, `getName(): 'string_collection'`, `convertToPHPValue → StringCollection`, DB stores JSON array of strings.

- [ ] **Step 1: Look at an existing custom type in the project as a template**

Read `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php` (or `ThermalExposureLimitsType.php`). Follow the same pattern.

- [ ] **Step 2: Write `CasNumberTypeTest`**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Infrastructure\Database\DBAL;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Infrastructure\Database\DBAL\CasNumberType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class CasNumberTypeTest extends TestCase
{
    private CasNumberType $type;

    protected function setUp(): void
    {
        if (!\Doctrine\DBAL\Types\Type::hasType('cas_number')) {
            \Doctrine\DBAL\Types\Type::addType('cas_number', CasNumberType::class);
        }
        $this->type = \Doctrine\DBAL\Types\Type::getType('cas_number');
    }

    public function testToPhpAndBack(): void
    {
        $plat = new PostgreSQLPlatform();
        self::assertNull($this->type->convertToPHPValue(null, $plat));
        self::assertNull($this->type->convertToDatabaseValue(null, $plat));

        $cas = $this->type->convertToPHPValue('107-21-1', $plat);
        self::assertInstanceOf(CasNumber::class, $cas);
        self::assertSame('107-21-1', $cas->value);

        self::assertSame('107-21-1', $this->type->convertToDatabaseValue(CasNumber::fromString('107-21-1'), $plat));
    }
}
```

- [ ] **Step 3: Implement `CasNumberType`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Database\DBAL;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class CasNumberType extends Type
{
    public const NAME = 'cas_number';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'VARCHAR(15)';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?CasNumber
    {
        return $value === null ? null : CasNumber::fromString((string)$value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof CasNumber) {
            throw new \LogicException('Expected CasNumber, got ' . get_debug_type($value));
        }
        return $value->value;
    }

    public function getName(): string { return self::NAME; }
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool { return true; }
}
```

- [ ] **Step 4: Implement `StringCollectionType`**

```php
<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Database\DBAL;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * JSONB<list<string>> ↔ StringCollection. Читаем как коллекцию, пишем через
 * JsonSerializable (StringCollection умеет). Расширяет JsonType, чтобы получить
 * готовые SQL-декларации и корректную работу с jsonb на Postgres.
 */
final class StringCollectionType extends JsonType
{
    public const NAME = 'string_collection';

    public function convertToPHPValue($value, AbstractPlatform $platform): StringCollection
    {
        $arr = parent::convertToPHPValue($value, $platform);
        if (!is_array($arr)) return new StringCollection();
        return new StringCollection(...array_map('strval', array_values($arr)));
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if ($value instanceof StringCollection) {
            return parent::convertToDatabaseValue($value->getList(), $platform);
        }
        return parent::convertToDatabaseValue($value ?? [], $platform);
    }

    public function getName(): string { return self::NAME; }
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool { return true; }
}
```

- [ ] **Step 5: Register in `app/config/packages/doctrine.yaml`**

Add under `dbal.types:` (alongside existing entries):

```yaml
            cas_number: App\ChemicalResistance\Infrastructure\Database\DBAL\CasNumberType
            string_collection: App\Shared\Infrastructure\Database\DBAL\StringCollectionType
```

Add under `orm.mappings:` (alongside `Users`, `Coatings`, `Proposals`, `ValueObject`):

```yaml
            ChemicalResistance:
                is_bundle: false
                type: xml
                dir: '%kernel.project_dir%/src/ChemicalResistance/Infrastructure/Database/ORM/Aggregate'
                prefix: 'App\ChemicalResistance\Domain\Aggregate'
                alias: ChemicalResistance
```

- [ ] **Step 6: Run type test — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Infrastructure/Database/DBAL/`

- [ ] **Step 7: Stop for review.**

---

### Task 8: DDL migration (three tables + indexes)

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260718000001.php`

**Interfaces:**
- Produces: three tables ready for ORM mapping in Task 9. No PHP class contract.

- [ ] **Step 1: Generate migration skeleton**

Do NOT use `doctrine:migrations:diff` (it will try to autodetect ORM state we haven't wired yet). Create the file by hand, following the existing pattern in `app/src/Shared/Infrastructure/Database/Migrations/` (look at a recent migration to mirror style).

Filename: `Version20260718000001.php`. Class name matches file.

- [ ] **Step 2: Write the migration**

```php
<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chemical_resistance_{substance,note,assessment} tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chemical_resistance_substance (
                id UUID PRIMARY KEY,
                canonical_name VARCHAR(200) NOT NULL,
                canonical_name_key VARCHAR(200) NOT NULL,
                cas VARCHAR(15) NULL,
                aliases JSONB NOT NULL DEFAULT '[]'::jsonb
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_substance_canonical_key ON chemical_resistance_substance (canonical_name_key)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_substance_cas ON chemical_resistance_substance (cas) WHERE cas IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_substance_aliases_gin ON chemical_resistance_substance USING gin (aliases jsonb_path_ops)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chemical_resistance_note (
                id UUID PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                description TEXT NOT NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chemical_resistance_assessment (
                id UUID PRIMARY KEY,
                coating_id UUID NOT NULL REFERENCES coatings_coating(id) ON DELETE CASCADE,
                substance_id UUID NOT NULL REFERENCES chemical_resistance_substance(id) ON DELETE RESTRICT,
                grade VARCHAR(2) NOT NULL,
                max_temperature_celsius SMALLINT NOT NULL DEFAULT 40,
                note_ids JSONB NOT NULL DEFAULT '[]'::jsonb
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_assessment_coating_substance ON chemical_resistance_assessment (coating_id, substance_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_assessment_coating ON chemical_resistance_assessment (coating_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_assessment_substance ON chemical_resistance_assessment (substance_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_assessment_coating_grade ON chemical_resistance_assessment (coating_id, grade)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS chemical_resistance_assessment');
        $this->addSql('DROP TABLE IF EXISTS chemical_resistance_note');
        $this->addSql('DROP TABLE IF EXISTS chemical_resistance_substance');
    }
}
```

- [ ] **Step 3: Run migration in dev**

Run: `cd app && bin/console doctrine:migrations:migrate -n`
Expected: three tables created; running twice — no-op (`IF NOT EXISTS`).

- [ ] **Step 4: Verify schema**

Run:
```bash
cd app && bin/console dbal:run-sql "\\d chemical_resistance_substance" || true
cd app && bin/console dbal:run-sql "\\d chemical_resistance_assessment" || true
```
Expected: columns and indexes as spec'd.

- [ ] **Step 5: Stop for review.**

---

### Task 9: ORM XML + Doctrine repository implementations

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Database/ORM/Aggregate/Substance.Substance.orm.xml`
- Create: `app/src/ChemicalResistance/Infrastructure/Database/ORM/Aggregate/Note.Note.orm.xml`
- Create: `app/src/ChemicalResistance/Infrastructure/Database/ORM/Aggregate/Assessment.Assessment.orm.xml`
- Create: `app/src/ChemicalResistance/Infrastructure/Repository/DoctrineSubstanceRepository.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Repository/DoctrineNoteRepository.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Repository/DoctrineAssessmentRepository.php`

**Interfaces:**
- Consumes: aggregates, DBAL types, Doctrine ORM.
- Produces: concrete repository classes bound to interfaces via Symfony DI autowiring.

- [ ] **Step 1: Look at an existing ORM XML**

Read `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` to mirror the style (`<entity name="...">`, `<field .../>`, `<id .../>`).

- [ ] **Step 2: Write `Note.Note.orm.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                      https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
    <entity name="App\ChemicalResistance\Domain\Aggregate\Note\Note" table="chemical_resistance_note">
        <id name="id" type="uuid" column="id"/>
        <field name="title" type="string" length="200" column="title"/>
        <field name="description" type="text" column="description"/>
    </entity>
</doctrine-mapping>
```

- [ ] **Step 3: Write `Substance.Substance.orm.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                      https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
    <entity name="App\ChemicalResistance\Domain\Aggregate\Substance\Substance" table="chemical_resistance_substance">
        <id name="id" type="uuid" column="id"/>
        <field name="canonicalName"    type="string" length="200" column="canonical_name"/>
        <field name="canonicalNameKey" type="string" length="200" column="canonical_name_key"/>
        <field name="cas"              type="cas_number"          column="cas" nullable="true"/>
        <field name="aliases"          type="string_collection"    column="aliases"/>
    </entity>
</doctrine-mapping>
```

Note: `Substance.$specification` is a runtime dependency, NOT persisted. It's injected via a Doctrine post-load event listener (see Step 6 below) or via a factory in the repository. We use the second: repository `find*` calls hydrate then inject spec via `Aggregate::__unserialize`-style or via reflection setter. Simplest: expose a `postLoad` method on `Substance` and register a listener.

**Simplification recommended:** avoid Doctrine event listeners; instead, extend `Substance::__construct` to accept null `$specification` and add `Substance::setSpecification()` package-scoped method the Doctrine repository calls after hydration. Rule of thumb: setters return void, don't invoke specification when spec is null. Alternative — do NOT hydrate the spec at all; use it only in Application handlers where you construct/setName. Adjust `Substance` accordingly (see refactor step below).

- [ ] **Step 4: Refactor `Substance` to make spec optional at hydration time**

Change `Substance::__construct` last param to `?SubstanceSpecification $specification = null`. Add `public function setSpecification(SubstanceSpecification $spec): void { $this->specification = $spec; }`. In setters (`setCanonicalName`, `setCas`), only call `$this->specification->…->satisfy($this)` when `isset($this->specification)`.

Rationale: Doctrine reconstructs entities via reflection, bypassing the constructor. Handlers that mutate a persisted entity must first inject spec via `setSpecification()`. Enforcement lives in the Application layer, not the aggregate.

- [ ] **Step 5: Same refactor for `Assessment`**

Make `AssessmentSpecification` and `NoteRepository` (consistency) optional in constructor; add `setSpecification()` and `setNotesRepositoryForConsistency()`. Setters skip validation when unset. Handlers wire them in before mutation.

- [ ] **Step 6: Write `Assessment.Assessment.orm.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <entity name="App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment" table="chemical_resistance_assessment">
        <id name="id" type="uuid" column="id"/>
        <field name="coatingId"   type="uuid"              column="coating_id"/>
        <field name="substanceId" type="uuid"              column="substance_id"/>
        <field name="grade"       type="string" length="2" column="grade"/>
        <field name="maxTemperatureCelsius" type="smallint" column="max_temperature_celsius"/>
        <field name="noteIds"     type="string_collection" column="note_ids"/>
    </entity>
</doctrine-mapping>
```

Wait — `grade` is `Grade` enum in domain, `maxTemperature` is VO. XML mapping only supports flat scalars. Options:
1. Store scalars (`private string $grade`, `private int $maxTemperatureCelsius`) and expose typed getters that `Grade::from()` and `AssessmentTemperature::fromInt()` on the fly. Simple, works with Doctrine reflection hydration.
2. Custom Doctrine embeddable/type.

Pick option 1. Refactor `Assessment` accordingly:
- Backing fields: `private string $grade`, `private int $maxTemperatureCelsius`, `private StringCollection $noteIds`.
- Getters return typed VOs: `getGrade(): Grade => Grade::from($this->grade)`; `getMaxTemperature(): AssessmentTemperature => AssessmentTemperature::fromInt($this->maxTemperatureCelsius)`.
- Setters accept VOs and store scalars.
- Constructor accepts VOs, stores scalars, applies default via `AssessmentTemperature::default()->celsius`.
- Adjust `AssessmentTest` if needed.

- [ ] **Step 7: Implement `DoctrineNoteRepository`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineNoteRepository implements NoteRepository
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(Note $note): void { $this->em->persist($note); $this->em->flush(); }
    public function remove(Note $note): void { $this->em->remove($note); $this->em->flush(); }
    public function find(Uuid $id): ?Note { return $this->em->find(Note::class, $id); }

    public function findAllByIds(array $ids): array
    {
        if (empty($ids)) return [];
        $notes = $this->em->createQueryBuilder()
            ->select('n')->from(Note::class, 'n')
            ->where('n.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
        // preserve input order, drop missing
        $byId = [];
        foreach ($notes as $n) { $byId[$n->getId()] = $n; }
        $ordered = [];
        foreach ($ids as $id) { if (isset($byId[$id])) $ordered[] = $byId[$id]; }
        return $ordered;
    }
}
```

- [ ] **Step 8: Implement `DoctrineSubstanceRepository`**

Same pattern. Additional methods:
- `findByCanonicalNameKey(string $key)` — QueryBuilder WHERE `s.canonicalNameKey = :key`.
- `findByCas(CasNumber $cas)` — QueryBuilder WHERE `s.cas = :cas` (Doctrine will use `CasNumberType` for the parameter).
- `save(Substance $s)` — persist + flush; before returning, `$s->setSpecification($this->makeSpec())` so caller can continue using it. Actually simpler: don't re-inject on save, only on find*.
- `find(Uuid $id): ?Substance` — after `em->find`, if not null, call `$s->setSpecification($this->makeSpec())`. Also for `findByCanonicalNameKey`, `findByCas`, `findAllByIds`.

`makeSpec()` returns a fresh `SubstanceSpecification` constructed with `$this` as the repo dep (self-reference is fine, spec only reads).

- [ ] **Step 9: Implement `DoctrineAssessmentRepository`**

Same pattern. Additional:
- `findByCoatingAndSubstance(Uuid, Uuid): ?Assessment`.
- `findAllByCoating(Uuid): list<Assessment>` — order by substance canonical (join needed via subquery/JPQL) OR just by id; UI-side sorting is fine, keep repo dumb.
- `findAllBySubstance(Uuid): list<Assessment>`.
- `paginateByCoating(Uuid, ?string $search, int $page, int $pageSize): PaginationResult` — see spec, join substance to filter by canonical/aliases/CAS. See existing `CoatingFinder` for QueryBuilder+Paginator pattern.

After every `find*`, call `$a->setSpecification($this->makeSpec()); $a->setNotesRepositoryForConsistency($this->notes)` to rehydrate runtime deps. Constructor of repo takes `EntityManagerInterface $em, NoteRepository $notes`.

- [ ] **Step 10: Bind interfaces to implementations**

In `app/config/services.yaml`:

```yaml
    App\ChemicalResistance\Domain\Repository\NoteRepository:
        alias: App\ChemicalResistance\Infrastructure\Repository\DoctrineNoteRepository
    App\ChemicalResistance\Domain\Repository\SubstanceRepository:
        alias: App\ChemicalResistance\Infrastructure\Repository\DoctrineSubstanceRepository
    App\ChemicalResistance\Domain\Repository\AssessmentRepository:
        alias: App\ChemicalResistance\Infrastructure\Repository\DoctrineAssessmentRepository
```

Verify the file convention — some projects auto-wire based on `App\*` namespace and don't need explicit aliases; check `services.yaml` head. If the codebase already relies on autowire+autoconfigure, this block may be redundant. Only add if needed.

- [ ] **Step 11: Run `cache:clear` and `dbal:run-sql "SELECT 1"` — sanity check**

Run:
```bash
cd app && bin/console cache:clear --env=dev
cd app && bin/console doctrine:schema:validate
```
Expected: `[Mapping] OK`, `[Database] OK`. If mapping fails — fix XML and re-run.

- [ ] **Step 12: Stop for review.**

---

### Task 10: Round-trip persistence test

**Files:**
- Test: `app/tests/Functional/ChemicalResistance/PersistenceRoundTripTest.php`

**Interfaces:**
- Consumes: Doctrine repositories, Symfony `KernelTestCase`.

- [ ] **Step 1: Look at an existing functional test for pattern**

Read a functional test in `app/tests/Functional/Coatings/` (e.g. any `*HandlerTest.php`). Note how `KernelTestCase::bootKernel()` + `EntityManager` teardown are set up.

- [ ] **Step 2: Write the test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\ChemicalResistance;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PersistenceRoundTripTest extends KernelTestCase
{
    public function testSaveAndLoadAll(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        /** @var SubstanceRepository $subs */
        $subs = $c->get(SubstanceRepository::class);
        /** @var NoteRepository $notes */
        $notes = $c->get(NoteRepository::class);
        /** @var AssessmentRepository $ass */
        $ass = $c->get(AssessmentRepository::class);

        // Precondition: a real coating exists. Use any seeded one, or create one in the test.
        // For this smoke test we synthesise a UUID (FK ON DELETE CASCADE means we must actually have coating).
        // Simpler: fetch first coating from coatings_coating.
        $coatingId = Uuid::fromString(
            $c->get('doctrine.dbal.default_connection')->fetchOne('SELECT id::text FROM coatings_coating LIMIT 1')
        );

        // Substance
        $sub = new Substance(Uuid::v4(), 'Вода', CasNumber::fromString('7732-18-5'),
            new StringCollection('Water', 'H2O'), $subs->makeSpec());
        $subs->save($sub);

        // Note
        $note = new Note(Uuid::v4(), 'Изменение цвета покрытия', 'Тест-описание.');
        $notes->save($note);

        // Assessment
        $a = new Assessment(
            Uuid::v4(), $coatingId, Uuid::fromString($sub->getId()),
            Grade::R, AssessmentTemperature::fromInt(70),
            new StringCollection($note->getId()),
            $ass->makeSpec(), $notes,
        );
        $ass->save($a);

        // Round-trip
        $loaded = $subs->findByCanonicalNameKey('вода');
        self::assertNotNull($loaded);
        self::assertSame('7732-18-5', (string)$loaded->getCas());
        self::assertSame(['Water', 'H2O'], $loaded->getAliases()->getList());

        $loadedA = $ass->findByCoatingAndSubstance($coatingId, Uuid::fromString($sub->getId()));
        self::assertSame(Grade::R, $loadedA->getGrade());
        self::assertSame(70, $loadedA->getMaxTemperature()->celsius);
    }
}
```

Note: `makeSpec()` on the repositories is a convenience method (see Task 9 step 8-9) that constructs the fresh specification bag. If you skipped that, construct the spec inline with `new SubstanceSpecification(new UniqueSubstanceNameSpecification($subs), new UniqueCasSpecification($subs))`.

- [ ] **Step 3: Run test — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Functional/ChemicalResistance/PersistenceRoundTripTest.php`

- [ ] **Step 4: Stop for review — end of Phase 2.**

---

## Phase 3 — Application (CRUD)

### Task 11: DTOs

**Files:**
- Create: `app/src/ChemicalResistance/Application/DTO/SubstanceDTO.php`
- Create: `app/src/ChemicalResistance/Application/DTO/NoteDTO.php`
- Create: `app/src/ChemicalResistance/Application/DTO/AssessmentDTO.php`
- Create: `app/src/ChemicalResistance/Application/DTO/AssessmentRowDTO.php`
- Create: `app/src/ChemicalResistance/Application/DTO/SubstanceMatchDTO.php`

**Interfaces:**
- Produces plain data classes (no behaviour). All fields `public readonly`, constructors PHP-8-style.

- [ ] **Step 1: Create DTOs**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\DTO;

final readonly class SubstanceDTO
{
    public function __construct(
        public ?string $id,
        public string  $canonicalName,
        public ?string $cas,              // "107-21-1" or null
        /** @var list<string> */
        public array   $aliases,
    ) {}
}

final readonly class NoteDTO
{
    public function __construct(public ?string $id, public string $title, public string $description) {}
}

final readonly class AssessmentDTO
{
    public function __construct(
        public ?string $id,
        public string  $coatingId,
        public string  $substanceId,
        public string  $grade,            // 'R'|'NR'|'LR'|'FS'|'NT'
        public int     $maxTemperatureCelsius,
        /** @var list<string> */
        public array   $noteIds,
    ) {}
}

// Read-side view of a row in the modal's chemical-resistance table.
final readonly class AssessmentRowDTO
{
    public function __construct(
        public string $substanceId,
        public string $canonicalName,
        public ?string $cas,
        /** @var list<string> */
        public array  $aliases,
        public string $grade,
        public int    $maxTemperatureCelsius,
        /** @var list<array{title:string,description:string,isSystem:bool}> */
        public array  $notes,
    ) {}
}

// For CoatingDTO.matchedSubstances (list-card badge).
final readonly class SubstanceMatchDTO
{
    public function __construct(
        public string $substanceId,
        public string $canonicalName,
        public string $matchedVia,   // 'canonical' | 'alias' | 'cas'
    ) {}
}
```

Split into five files with corresponding namespace headers.

- [ ] **Step 2: Stop for review.**

---

### Task 12: Note CRUD handlers

**Files:**
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Note/CreateNote/CreateNoteCommand.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Note/CreateNote/CreateNoteCommandHandler.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Note/UpdateNote/UpdateNoteCommand.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Note/UpdateNote/UpdateNoteCommandHandler.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Note/DeleteNote/DeleteNoteCommand.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Note/DeleteNote/DeleteNoteCommandHandler.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/UseCase/Command/Note/NoteCrudHandlersTest.php`

**Interfaces:**
- Consumes: `NoteRepository`, `AssessmentRepository` (for delete-blocking).
- Produces:
  - `CreateNoteCommand(string $title, string $description)`; handler returns `string $id` (UUID).
  - `UpdateNoteCommand(string $id, string $title, string $description)`; handler returns void.
  - `DeleteNoteCommand(string $id)`; handler throws `AppException` if any assessment still references this note.

- [ ] **Step 1: Look at existing pattern**

Read `app/src/Coatings/Application/UseCase/Command/CreateManufacturer/` for structure (Command as `final readonly`, Handler with `__invoke` decorated by `#[AsMessageHandler]` if Symfony Messenger is used, or just plain).

- [ ] **Step 2: Implement CreateNoteCommand + Handler**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote;

final readonly class CreateNoteCommand
{
    public function __construct(public string $title, public string $description) {}
}
```

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use Symfony\Component\Uid\Uuid;

final class CreateNoteCommandHandler
{
    public function __construct(private NoteRepository $repo) {}

    public function __invoke(CreateNoteCommand $c): string
    {
        $note = new Note(Uuid::v4(), $c->title, $c->description);
        $this->repo->save($note);
        return $note->getId();
    }
}
```

- [ ] **Step 3: Implement UpdateNoteCommand + Handler**

```php
final readonly class UpdateNoteCommand {
    public function __construct(public string $id, public string $title, public string $description) {}
}

final class UpdateNoteCommandHandler
{
    public function __construct(private NoteRepository $repo) {}

    public function __invoke(UpdateNoteCommand $c): void
    {
        $note = $this->repo->find(Uuid::fromString($c->id))
            ?? throw new AppException('Примечание не найдено.');
        $note->setTitle($c->title);
        $note->setDescription($c->description);
        $this->repo->save($note);
    }
}
```

- [ ] **Step 4: Implement DeleteNoteCommand + Handler (with block-if-referenced)**

```php
final readonly class DeleteNoteCommand { public function __construct(public string $id) {} }

final class DeleteNoteCommandHandler
{
    public function __construct(
        private NoteRepository $notes,
        private AssessmentRepository $assessments,
    ) {}

    public function __invoke(DeleteNoteCommand $c): void
    {
        $note = $this->notes->find(Uuid::fromString($c->id))
            ?? throw new AppException('Примечание не найдено.');

        // Cheap check: query assessment where note_ids @> [id::jsonb]
        // Add repo method: countAssessmentsWithNoteId(string $noteId): int.
        // Alternative: pull assessments where note_ids contains id.
        $used = $this->assessments->countAssessmentsWithNoteId($c->id);
        if ($used > 0) {
            throw new AppException(sprintf(
                'Примечание используется в %d оценках, удаление невозможно.', $used,
            ));
        }
        $this->notes->remove($note);
    }
}
```

Extend `AssessmentRepository` interface + Doctrine impl with `countAssessmentsWithNoteId(string $id): int` — SQL `SELECT COUNT(*) FROM chemical_resistance_assessment WHERE note_ids @> :id::jsonb` using DBAL native SQL (JPQL doesn't have `@>`).

- [ ] **Step 5: Write functional test — Note CRUD**

```php
final class NoteCrudHandlersTest extends KernelTestCase
{
    public function testCreateUpdateDelete(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $create = $c->get(CreateNoteCommandHandler::class);
        $update = $c->get(UpdateNoteCommandHandler::class);
        $delete = $c->get(DeleteNoteCommandHandler::class);
        $notes  = $c->get(NoteRepository::class);

        $id = $create(new CreateNoteCommand('T1', 'D1'));
        $loaded = $notes->find(Uuid::fromString($id));
        self::assertSame('T1', $loaded->getTitle());

        $update(new UpdateNoteCommand($id, 'T2', 'D2'));
        self::assertSame('T2', $notes->find(Uuid::fromString($id))->getTitle());

        $delete(new DeleteNoteCommand($id));
        self::assertNull($notes->find(Uuid::fromString($id)));
    }

    public function testDeleteBlockedWhenReferenced(): void
    {
        // Create note, create assessment referencing it, try delete → expect AppException.
        // ...omitted here; follow the same wiring as above.
    }
}
```

- [ ] **Step 6: Run test — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Functional/ChemicalResistance/Application/UseCase/Command/Note/`

- [ ] **Step 7: Stop for review.**

---

### Task 13: Substance CRUD handlers

**Files:**
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Substance/CreateSubstance/{CreateSubstanceCommand,CreateSubstanceCommandHandler}.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Substance/UpdateSubstance/{UpdateSubstanceCommand,UpdateSubstanceCommandHandler}.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Substance/DeleteSubstance/{DeleteSubstanceCommand,DeleteSubstanceCommandHandler}.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/UseCase/Command/Substance/SubstanceCrudHandlersTest.php`

**Interfaces:**
- `CreateSubstanceCommand(string $canonicalName, ?string $cas, list<string> $aliases)` → returns `string $id`.
- `UpdateSubstanceCommand(string $id, string $canonicalName, ?string $cas, list<string> $aliases)` → void.
- `DeleteSubstanceCommand(string $id)` → void; blocked if any assessment references (ON DELETE RESTRICT will throw at DB level; catch and rethrow as AppException with a clear message).

- [ ] **Step 1: Implement Create handler**

```php
final class CreateSubstanceCommandHandler
{
    public function __construct(private SubstanceRepository $repo) {}

    public function __invoke(CreateSubstanceCommand $c): string
    {
        $cas = $c->cas !== null ? CasNumber::fromString($c->cas) : null;
        $sub = new Substance(
            Uuid::v4(),
            $c->canonicalName,
            $cas,
            new StringCollection(...$c->aliases),
            $this->makeSpec(),
        );
        $this->repo->save($sub);
        return $sub->getId();
    }

    private function makeSpec(): SubstanceSpecification
    {
        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($this->repo),
            new UniqueCasSpecification($this->repo),
        );
    }
}
```

Note: `SubstanceRepository::save()` already flushes; the specification checks happen inside `Substance::setCanonicalName` and `setCas` during construction.

- [ ] **Step 2: Implement Update handler**

Similar; load by id, `setCanonicalName`, `setCas`, replace `aliases` (compute add/remove diff — or naively call `removeAlias` on old ones then `addAlias` on new; simpler: replace via reflection-free method: add `Substance::replaceAliases(array): void` that clears and re-adds).

Add `Substance::replaceAliases(array $aliases): void` to the aggregate; it just `$this->aliases = new StringCollection(); foreach ($aliases as $a) $this->addAlias($a);`. That preserves canonical/alias-conflict rules.

- [ ] **Step 3: Implement Delete handler**

```php
final class DeleteSubstanceCommandHandler
{
    public function __construct(private SubstanceRepository $repo) {}

    public function __invoke(DeleteSubstanceCommand $c): void
    {
        $sub = $this->repo->find(Uuid::fromString($c->id))
            ?? throw new AppException('Вещество не найдено.');
        try {
            $this->repo->remove($sub);
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            throw new AppException('Вещество используется в оценках химстойкости, удаление невозможно.');
        }
    }
}
```

- [ ] **Step 4: Write functional test**

Analogous to Note CRUD test: create → find → update → delete happy paths; delete-blocked path.

- [ ] **Step 5: Run — expect pass**

- [ ] **Step 6: Stop for review.**

---

### Task 14: Assessment CRUD handlers

**Files:**
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Assessment/CreateAssessment/{CreateAssessmentCommand,CreateAssessmentCommandHandler}.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Assessment/UpdateAssessment/{UpdateAssessmentCommand,UpdateAssessmentCommandHandler}.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Command/Assessment/DeleteAssessment/{DeleteAssessmentCommand,DeleteAssessmentCommandHandler}.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/UseCase/Command/Assessment/AssessmentCrudHandlersTest.php`

**Interfaces:**
- `CreateAssessmentCommand(string $coatingId, string $substanceId, string $grade, ?int $maxTemperatureCelsius, list<string> $noteIds)` → `string $id`.
- `UpdateAssessmentCommand(string $id, string $grade, ?int $maxTemperatureCelsius, list<string> $noteIds)` → void.
- `DeleteAssessmentCommand(string $id)` → void.

- [ ] **Step 1: Implement Create handler**

```php
final class CreateAssessmentCommandHandler
{
    public function __construct(
        private AssessmentRepository $assessments,
        private NoteRepository $notes,
    ) {}

    public function __invoke(CreateAssessmentCommand $c): string
    {
        $maxTemp = $c->maxTemperatureCelsius !== null
            ? AssessmentTemperature::fromInt($c->maxTemperatureCelsius) : null;

        $a = new Assessment(
            Uuid::v4(),
            Uuid::fromString($c->coatingId),
            Uuid::fromString($c->substanceId),
            Grade::from($c->grade),
            $maxTemp,
            new StringCollection(...$c->noteIds),
            new AssessmentSpecification(
                new UniqueCoatingSubstanceAssessmentSpecification($this->assessments),
                new AssessmentNotesConsistencyValidator(),
            ),
            $this->notes,
        );
        $this->assessments->save($a);
        return $a->getId();
    }
}
```

- [ ] **Step 2: Implement Update / Delete handlers** (analogous, load-then-mutate).

- [ ] **Step 3: Write functional test**

Create Substance + Note, then Assessment; update grade/maxTemp; delete; verify.

Also test: creating duplicate (same coating+substance) throws AppException; noteIds referencing non-existent Note throws AppException.

- [ ] **Step 4: Run — expect pass**

- [ ] **Step 5: Stop for review.**

---

### Task 15: `SubstanceLookup` service (for importer)

**Files:**
- Create: `app/src/ChemicalResistance/Application/Service/SubstanceLookup.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/Service/SubstanceLookupTest.php`

**Interfaces:**
- Consumes: `SubstanceRepository`, `SubstanceNameNormalizer`.
- Produces:
  - `SubstanceLookup::findOrCreateByName(string $raw, ?CasNumber $cas = null): Substance`
    - If a Substance with matching normalized name exists → return it, and add `$raw` as alias if spelling differs from canonical.
    - Otherwise create new Substance with `$raw` as canonical and provided (or null) CAS.
    - CAS collision: if `$cas` provided and another Substance already has it → return that Substance and add `$raw` as its alias.

- [ ] **Step 1: Write test**

```php
final class SubstanceLookupTest extends KernelTestCase
{
    public function testCreatesNewWhenNotFound(): void { /* fresh raw name → new Substance */ }
    public function testReusesByCanonicalKey(): void  { /* insert one, look up variant spelling → returns same, alias added */ }
    public function testReusesByCas(): void { /* insert with CAS, look up different name + same CAS → same substance, alias added */ }
}
```

- [ ] **Step 2: Implement**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\Service;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\{
    SubstanceSpecification, UniqueSubstanceNameSpecification, UniqueCasSpecification
};
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

final class SubstanceLookup
{
    public function __construct(private SubstanceRepository $repo) {}

    public function findOrCreateByName(string $raw, ?CasNumber $cas = null): Substance
    {
        $raw = trim($raw);
        // 1. Prefer CAS match if given.
        if ($cas !== null) {
            $existing = $this->repo->findByCas($cas);
            if ($existing !== null) {
                if (!$existing->hasName($raw)) { $existing->addAlias($raw); $this->repo->save($existing); }
                return $existing;
            }
        }
        // 2. Try normalized name.
        $key = SubstanceNameNormalizer::normalize($raw);
        $existing = $this->repo->findByCanonicalNameKey($key);
        if ($existing !== null) {
            if (!$existing->hasName($raw)) { $existing->addAlias($raw); $this->repo->save($existing); }
            return $existing;
        }
        // 3. Create fresh.
        $sub = new Substance(Uuid::v4(), $raw, $cas, new StringCollection(), $this->spec());
        $this->repo->save($sub);
        return $sub;
    }

    private function spec(): SubstanceSpecification
    {
        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($this->repo),
            new UniqueCasSpecification($this->repo),
        );
    }
}
```

- [ ] **Step 3: Run — expect pass**

- [ ] **Step 4: Stop for review — end of Phase 3.**

---

## Phase 4 — Import pipeline

### Task 16: `GradeCellParser`

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Docx/ParsedAssessment.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Docx/GradeCellParser.php`
- Test: `app/tests/Unit/ChemicalResistance/Infrastructure/Docx/GradeCellParserTest.php`

**Interfaces:**
- Produces:
  - `ParsedAssessment(string $grade, ?int $maxTemperatureCelsius, list<string> $noteLabels)` — final readonly.
  - `GradeCellParser::parse(string $cell): ParsedAssessment` — throws `AppException` if cell has no recognizable grade.

Handles every real format from all three docx: `R`, `NR`, `LR`, `FS`, `NT`, `NT/FS`, `R, 60ºC`, `R, 60°C`, `R, Прим. 1`, `R, Прим. 1,4`, `R, Прим. 1, 70ºC`, `R, Прим. 1, 70ºC, Прим. 1` (dedup).

- [ ] **Step 1: Write test with every format found in docx**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Infrastructure\Docx;

use App\ChemicalResistance\Infrastructure\Docx\GradeCellParser;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class GradeCellParserTest extends TestCase
{
    /** @dataProvider cases */
    public function testParse(string $input, string $grade, ?int $maxT, array $noteLabels): void
    {
        $out = (new GradeCellParser())->parse($input);
        self::assertSame($grade, $out->grade);
        self::assertSame($maxT, $out->maxTemperatureCelsius);
        self::assertSame($noteLabels, $out->noteLabels);
    }

    public static function cases(): array
    {
        return [
            'plain R'      => ['R',           'R',  null, []],
            'plain NR'     => ['NR',          'NR', null, []],
            'plain LR'     => ['LR',          'LR', null, []],
            'plain FS'     => ['FS',          'FS', null, []],
            'plain NT'     => ['NT',          'NT', null, []],
            'nt-fs'        => ['NT/FS',       'NT', null, []],  // NT takes precedence; document as such
            'with temp º'  => ['R, 60ºC',     'R',  60,   []],
            'with temp °'  => ['R, 60°C',     'R',  60,   []],
            'note single'  => ['R, Прим. 1',  'R',  null, ['Прим. 1']],
            'note multi'   => ['R, Прим. 1,4','R',  null, ['Прим. 1', 'Прим. 4']],
            'combined'     => ['R, Прим. 1, 70ºC', 'R', 70, ['Прим. 1']],
            'with dupes'   => ['R, Прим. 1, 70ºC, Прим. 1', 'R', 70, ['Прим. 1']],
            'lowercase c'  => ['R, 60ºc',     'R',  60,   []],
            'spaced R prim'=> ['R,  Прим.  1', 'R', null, ['Прим. 1']],
        ];
    }

    public function testEmptyCellFails(): void
    {
        $this->expectException(AppException::class);
        (new GradeCellParser())->parse('  ');
    }
}
```

- [ ] **Step 2: Implement `ParsedAssessment`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Docx;

final readonly class ParsedAssessment
{
    public function __construct(
        public string $grade,
        public ?int   $maxTemperatureCelsius,
        /** @var list<string> */
        public array  $noteLabels,
    ) {}
}
```

- [ ] **Step 3: Implement `GradeCellParser`**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Docx;

use App\Shared\Infrastructure\Exception\AppException;

final class GradeCellParser
{
    private const GRADES = ['R', 'NR', 'LR', 'FS', 'NT'];

    public function parse(string $cell): ParsedAssessment
    {
        $cell = trim(preg_replace('/\s+/u', ' ', $cell));
        if ($cell === '') {
            throw new AppException('Пустая ячейка оценки.');
        }

        // Special case: "NT/FS" — take first (NT).
        if (preg_match('#^(NT)/FS$#i', $cell, $m)) {
            return new ParsedAssessment(strtoupper($m[1]), null, []);
        }

        // Split by comma, but keep "Прим. 1,4" together — pre-normalize.
        // Strategy: replace "Прим. 1,4" → "Прим. 1|4" so comma-split doesn't cut inside.
        $work = preg_replace('/(Прим\.\s*\d+)(?:\s*,\s*(\d+))+/u', function ($m) use (&$work) {
            // Rebuild "Прим. N,M,K" → "Прим. N|M|K"
            $s = $m[0];
            $s = preg_replace('/\s*,\s*/u', '|', $s);
            return $s;
        }, $cell) ?? $cell;

        $parts = array_map('trim', explode(',', $work));

        $grade = null;
        $maxT = null;
        $noteLabels = [];

        foreach ($parts as $p) {
            if ($p === '') continue;

            // Grade?
            if (in_array(strtoupper($p), self::GRADES, true)) {
                $grade ??= strtoupper($p);
                continue;
            }
            // Temperature: "60ºC", "60°C", "60ºc", "60 °C", etc.
            if (preg_match('/^(\d+)\s*[°º]?[CСcс]$/u', $p, $m)) {
                $maxT = (int)$m[1];
                continue;
            }
            // Note ref, single or joined by | (from earlier collapse).
            if (preg_match('/^Прим\.\s*(\d+(?:\|\d+)*)$/u', $p, $m)) {
                foreach (explode('|', $m[1]) as $n) {
                    $label = 'Прим. ' . $n;
                    if (!in_array($label, $noteLabels, true)) $noteLabels[] = $label;
                }
                continue;
            }
            // Unknown token — silently skip (docx has occasional junk like "*Shell").
        }

        if ($grade === null) {
            throw new AppException(sprintf('Не удалось распознать оценку в ячейке «%s».', $cell));
        }
        return new ParsedAssessment($grade, $maxT, $noteLabels);
    }
}
```

- [ ] **Step 4: Run — expect pass** (fix parser until all fixtures green)

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Infrastructure/Docx/GradeCellParserTest.php`

- [ ] **Step 5: Stop for review.**

---

### Task 17: `DocxAssessmentParser` + fixture-docx + tests

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Docx/DocxParseResult.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Docx/ParsedNote.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Docx/ParsedRow.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Docx/DocxAssessmentParser.php`
- Create: `app/tests/Fixtures/ChemicalResistance/minimal.docx` — a 3-row docx with a small legend, built by hand or by the same script that reads the real docx (see below).
- Test: `app/tests/Unit/ChemicalResistance/Infrastructure/Docx/DocxAssessmentParserTest.php`

**Interfaces:**
- Produces:
  - `ParsedRow(string $substanceName, string $gradeCell)`.
  - `ParsedNote(string $label, string $title, string $description)`.
  - `DocxParseResult(list<ParsedRow> $rows, list<ParsedNote> $notes)`.
  - `DocxAssessmentParser::parse(string $docxPath): DocxParseResult` — throws on unreadable file.

- [ ] **Step 1: Check whether `phpoffice/phpword` is already in composer**

Run: `cd app && composer show | grep -i phpword`
- If present → use it.
- If not present → do NOT add a dependency; use the zip+xml approach (docx is a ZIP with `word/document.xml`). Sample extractor:
  ```php
  $zip = new \ZipArchive();
  $zip->open($docxPath);
  $xml = $zip->getFromName('word/document.xml');
  $zip->close();
  ```
  Then read `<w:tbl><w:tr><w:tc><w:p><w:t>` structure with `SimpleXMLElement` and namespaces. See the bash prototype earlier in the design brainstorm.

- [ ] **Step 2: Create fixture-docx**

Options:
  a. Manually create in LibreOffice/Word: table with 3 rows `1 | Water | R`, `2 | Ethanol | R, 60ºC`, `3 | Butanol | NR`; below the table, paragraphs:
     ```
     Примечание 1. Изменение цвета покрытия
     Покрытие может поменять цвет вследствие длительного контакта с веществом. Подобный эффект не влияет на химическую стойкость покрытия.
     ```
  b. Generate programmatically via `phpoffice/phpword` in a one-off script (`tests/Fixtures/generate_minimal.php`) and commit the resulting `.docx`.
  c. Take a real docx from `/Users/nikolay_vanzhin/Downloads/…` (author's machine), extract first 3 rows + one note, save as `tests/Fixtures/ChemicalResistance/minimal.docx`.

Pick (c) if fastest; (b) if you want reproducibility.

- [ ] **Step 3: Write `DocxAssessmentParserTest`**

```php
final class DocxAssessmentParserTest extends TestCase
{
    public function testParsesMinimalFixture(): void
    {
        $path = __DIR__ . '/../../../Fixtures/ChemicalResistance/minimal.docx';
        $out = (new DocxAssessmentParser())->parse($path);

        self::assertCount(3, $out->rows);
        self::assertSame('Water', $out->rows[0]->substanceName);
        self::assertSame('R', $out->rows[0]->gradeCell);
        self::assertSame('Ethanol', $out->rows[1]->substanceName);
        self::assertSame('R, 60ºC', $out->rows[1]->gradeCell);

        self::assertCount(1, $out->notes);
        self::assertSame('Прим. 1', $out->notes[0]->label);
        self::assertSame('Изменение цвета покрытия', $out->notes[0]->title);
        self::assertStringContainsString('поменять цвет', $out->notes[0]->description);
    }
}
```

- [ ] **Step 4: Implement `DocxAssessmentParser`**

Implementation outline (adjust based on step 1 decision):

```php
final class DocxAssessmentParser
{
    public function parse(string $path): DocxParseResult
    {
        if (!is_readable($path)) {
            throw new AppException("Файл не найден или недоступен: $path");
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new AppException("Не удалось открыть docx: $path");
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $rows = $this->parseRows($xp);
        $notes = $this->parseNotes($xp);
        return new DocxParseResult($rows, $notes);
    }

    /** @return list<ParsedRow> */
    private function parseRows(\DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query('//w:tbl/w:tr') as $tr) {
            $cells = [];
            foreach ($xp->query('.//w:tc', $tr) as $tc) {
                $text = '';
                foreach ($xp->query('.//w:t', $tc) as $t) { $text .= $t->textContent; }
                $cells[] = trim(preg_replace('/\s+/u', ' ', $text));
            }
            if (count($cells) < 3) continue;
            if (!ctype_digit($cells[0])) continue;    // header or non-data row
            $out[] = new ParsedRow($cells[1], $cells[2]);
        }
        return $out;
    }

    /** @return list<ParsedNote> */
    private function parseNotes(\DOMXPath $xp): array
    {
        // Collect all <w:p> body paragraphs after the last table; look for "Примечание N. <title>"
        // followed by paragraphs until the next "Примечание".
        $paragraphs = [];
        foreach ($xp->query('//w:body/w:p') as $p) {
            $t = '';
            foreach ($xp->query('.//w:t', $p) as $wt) { $t .= $wt->textContent; }
            $t = trim(preg_replace('/\s+/u', ' ', $t));
            if ($t !== '') $paragraphs[] = $t;
        }
        $out = [];
        $i = 0;
        while ($i < count($paragraphs)) {
            if (preg_match('/^Прим(?:ечание)?\s*(\d+)\.\s*(.+)$/u', $paragraphs[$i], $m)) {
                $label = 'Прим. ' . $m[1];
                $title = trim($m[2]);
                $descLines = [];
                $j = $i + 1;
                while ($j < count($paragraphs) &&
                       !preg_match('/^Прим(?:ечание)?\s*\d+\.\s/u', $paragraphs[$j])) {
                    $descLines[] = $paragraphs[$j];
                    $j++;
                }
                $out[] = new ParsedNote($label, $title, implode(' ', $descLines));
                $i = $j;
                continue;
            }
            $i++;
        }
        return $out;
    }
}
```

- [ ] **Step 5: Run — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Unit/ChemicalResistance/Infrastructure/Docx/DocxAssessmentParserTest.php`

- [ ] **Step 6: Stop for review.**

---

### Task 18: `ChemicalResistanceImporter`

**Files:**
- Create: `app/src/ChemicalResistance/Application/Service/ImportOptions.php`
- Create: `app/src/ChemicalResistance/Application/Service/ImportReport.php`
- Create: `app/src/ChemicalResistance/Application/Service/ChemicalResistanceImporter.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/Service/ChemicalResistanceImporterTest.php`

**Interfaces:**
- `ImportOptions(bool $dryRun, bool $overwrite, int $defaultMaxTemp = 40)`.
- `ImportReport(int $substancesCreated, int $substancesReused, int $aliasesAdded, int $assessmentsCreated, int $assessmentsUpdated, int $notesCreated, list<string> $conflicts, list<string> $warnings)`.
- `ChemicalResistanceImporter::__construct(SubstanceLookup, NoteRepository, AssessmentRepository, GradeCellParser)`.
- `ChemicalResistanceImporter::import(DocxParseResult, Uuid $coatingId, ImportOptions): ImportReport`.

Behaviour:
1. Create Notes for every distinct label in `$parsed->notes` (or reuse existing by title exact match — safest: always create new Notes, admin can merge later; simpler for v1: always create new).
2. Iterate `$parsed->rows`:
   - `SubstanceLookup->findOrCreateByName(row.substanceName)` — no CAS at import.
   - `GradeCellParser->parse(row.gradeCell)` → `ParsedAssessment`.
   - Resolve `noteLabels` to `noteIds` using the map built in step 1.
   - Upsert `Assessment(coatingId, substanceId, grade, maxTemp, noteIds)`. If existing and `!$options->overwrite` → log conflict, skip.
3. Return `ImportReport` with counts.
4. If `$options->dryRun` — do all lookups but never `save()` (wrap in a rollback-able unit of work, or run against a transaction and always rollback).

- [ ] **Step 1: Write functional test**

```php
final class ChemicalResistanceImporterTest extends KernelTestCase
{
    public function testImportFixtureCreatesEverything(): void
    {
        // Bootstrap kernel, get importer + a real coating id (any seed coating).
        // Feed a manually-built DocxParseResult (or parse the fixture from Task 17).
        // Assert counts in report, then verify DB state.
    }

    public function testReimportIsIdempotent(): void
    {
        // Import twice — second run should report 0 new substances, 0 new assessments.
    }
}
```

- [ ] **Step 2: Implement `ImportOptions` + `ImportReport` (plain data classes)**

- [ ] **Step 3: Implement `ChemicalResistanceImporter`**

```php
final class ChemicalResistanceImporter
{
    public function __construct(
        private SubstanceLookup $lookup,
        private NoteRepository $notes,
        private AssessmentRepository $assessments,
        private GradeCellParser $gradeParser,
    ) {}

    public function import(DocxParseResult $parsed, Uuid $coatingId, ImportOptions $opts): ImportReport
    {
        $counts = ['sub_created' => 0, 'sub_reused' => 0, 'aliases_added' => 0,
                   'ass_created' => 0, 'ass_updated' => 0, 'notes_created' => 0];
        $conflicts = []; $warnings = [];

        // 1. Notes
        $labelToId = [];
        foreach ($parsed->notes as $pn) {
            $note = new Note(Uuid::v4(), $pn->title, $pn->description);
            if (!$opts->dryRun) $this->notes->save($note);
            $labelToId[$pn->label] = $note->getId();
            $counts['notes_created']++;
        }

        // 2. Rows
        foreach ($parsed->rows as $row) {
            try { $g = $this->gradeParser->parse($row->gradeCell); }
            catch (AppException $e) { $warnings[] = "«{$row->substanceName}»: {$e->getMessage()}"; continue; }

            // Substance
            $subExisted = $this->lookup->findByNormalizedName($row->substanceName);   // pre-check (optional; simpler: skip)
            $sub = $this->lookup->findOrCreateByName($row->substanceName);
            if ($subExisted === null) $counts['sub_created']++; else $counts['sub_reused']++;
            // (Aliases-added count tracked inside SubstanceLookup optionally.)

            // Assessment
            $substanceId = Uuid::fromString($sub->getId());
            $existing = $this->assessments->findByCoatingAndSubstance($coatingId, $substanceId);
            if ($existing !== null && !$opts->overwrite) {
                $conflicts[] = sprintf('«%s»: оценка уже есть, пропущено.', $row->substanceName);
                continue;
            }

            $noteIds = new StringCollection(...array_map(fn(string $l) => $labelToId[$l] ?? '', $g->noteLabels));
            $noteIds = new StringCollection(...array_filter($noteIds->getList(), fn(string $x) => $x !== ''));

            $maxTemp = $g->maxTemperatureCelsius !== null
                ? AssessmentTemperature::fromInt($g->maxTemperatureCelsius) : null;

            if ($existing !== null) {
                $existing->setGrade(Grade::from($g->grade));
                $existing->setMaxTemperature($maxTemp ?? AssessmentTemperature::default());
                $existing->setNoteIds($noteIds, $this->notes);
                if (!$opts->dryRun) $this->assessments->save($existing);
                $counts['ass_updated']++;
            } else {
                $a = new Assessment(
                    Uuid::v4(), $coatingId, $substanceId,
                    Grade::from($g->grade), $maxTemp, $noteIds,
                    new AssessmentSpecification(
                        new UniqueCoatingSubstanceAssessmentSpecification($this->assessments),
                        new AssessmentNotesConsistencyValidator(),
                    ),
                    $this->notes,
                );
                if (!$opts->dryRun) $this->assessments->save($a);
                $counts['ass_created']++;
            }
        }

        return new ImportReport(
            substancesCreated: $counts['sub_created'],
            substancesReused: $counts['sub_reused'],
            aliasesAdded: $counts['aliases_added'],
            assessmentsCreated: $counts['ass_created'],
            assessmentsUpdated: $counts['ass_updated'],
            notesCreated: $counts['notes_created'],
            conflicts: $conflicts,
            warnings: $warnings,
        );
    }
}
```

`SubstanceLookup::findByNormalizedName` — add a method that only reads, doesn't create (for the pre-check). Or drop the sub_created/sub_reused distinction and just count differently.

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Stop for review.**

---

### Task 19: CLI import command

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Command/ImportChemicalResistanceCommand.php`
- Test: `app/tests/Functional/ChemicalResistance/Infrastructure/Command/ImportChemicalResistanceCommandTest.php`

**Interfaces:**
- `bin/console coatings:chemical-resistance:import <docx-path> --coating-title=<T> [--dry-run] [--overwrite]`

- [ ] **Step 1: Implement command**

```php
<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Command;

use App\ChemicalResistance\Application\Service\ChemicalResistanceImporter;
use App\ChemicalResistance\Application\Service\ImportOptions;
use App\ChemicalResistance\Infrastructure\Docx\DocxAssessmentParser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'coatings:chemical-resistance:import', description: 'Импортирует таблицу химстойкости из docx для указанного покрытия.')]
final class ImportChemicalResistanceCommand extends Command
{
    public function __construct(
        private DocxAssessmentParser $parser,
        private ChemicalResistanceImporter $importer,
        private Connection $dbal,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addArgument('docx', InputArgument::REQUIRED, 'Путь к .docx')
            ->addOption('coating-title', null, InputOption::VALUE_REQUIRED, 'Точное название покрытия')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Разобрать и напечатать отчёт, ничего не писать')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Перезаписывать существующие оценки');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $docx = $input->getArgument('docx');
        $title = $input->getOption('coating-title')
            ?? throw new \InvalidArgumentException('--coating-title обязателен');

        $row = $this->dbal->fetchAssociative('SELECT id::text AS id FROM coatings_coating WHERE title = ?', [$title]);
        if ($row === false) {
            $io->error("Покрытие «$title» не найдено.");
            return Command::FAILURE;
        }
        $coatingId = Uuid::fromString($row['id']);

        $parsed = $this->parser->parse($docx);
        $opts = new ImportOptions(
            dryRun: (bool)$input->getOption('dry-run'),
            overwrite: (bool)$input->getOption('overwrite'),
        );
        $report = $this->importer->import($parsed, $coatingId, $opts);

        $io->success(sprintf(
            'Импорт %s: substance created %d / reused %d, assessments created %d / updated %d, notes %d, conflicts %d',
            $opts->dryRun ? '(dry-run)' : '',
            $report->substancesCreated, $report->substancesReused,
            $report->assessmentsCreated, $report->assessmentsUpdated,
            $report->notesCreated, count($report->conflicts),
        ));
        foreach ($report->conflicts as $c) { $io->text(" - $c"); }
        foreach ($report->warnings as $w)  { $io->warning($w); }
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Write functional test**

Run the command in-process via `CommandTester` against the minimal fixture; assert exit code 0 and expected report line.

- [ ] **Step 3: Manual smoke run against a real docx**

Run:
```bash
cd app && bin/console coatings:chemical-resistance:import \
    "/Users/nikolay_vanzhin/Downloads/Литатанк Классик_Перечень стойкости к веществ ??.docx" \
    --coating-title="Литатанк Классик" \
    --dry-run
```
Expected: report with ~950 substances created, ~1017 assessments created, ~4 notes.

- [ ] **Step 4: Stop for review — end of Phase 4.**

---

## Phase 5 — Data seeding (JSON files)

### Task 20: Parse three real docx → curated JSON seed files

**Files (produced by the implementer, then committed to git):**
- Create: `app/src/ChemicalResistance/Infrastructure/Database/Seed/litatank_classic.json`
- Create: `app/src/ChemicalResistance/Infrastructure/Database/Seed/litatank_plus.json`
- Create: `app/src/ChemicalResistance/Infrastructure/Database/Seed/litatank_standart.json`

**Interfaces:**
- Produces JSON files consumed by seed migrations in Task 22.
- JSON schema (per file):
  ```json
  {
    "coating_title": "Литатанк Классик",
    "notes": [
      {"placeholder_label": "Прим. 1", "title": "…", "description": "…"}
    ],
    "substances": [
      {"canonical": "Этиленгликоль", "cas": "107-21-1",
       "aliases": ["Ethylene glycol", "1,2-Ethanediol", "1,2-Dihydroxyethane", "1,2-Etandiol"]}
    ],
    "assessments": [
      {"substance": "Этиленгликоль", "grade": "R", "max_temperature": null, "notes": []}
    ]
  }
  ```
  - `assessments[].substance` references `substances[].canonical` (exact string match after any admin edits).
  - `assessments[].notes` references `notes[].placeholder_label` (per-file scope).
  - `substances[].cas` = null when unknown/unverified.
  - `assessments[].max_temperature` = null → seed migration inserts DB default (40).

Special task: **this is a one-time claude-authored deliverable.** The assistant (claude) runs the import parser locally, curates Russian canonical names and CAS numbers for the ~top 300 substances by frequency, and hand-edits the resulting JSON. The implementer just verifies the committed files exist and are valid JSON.

- [ ] **Step 1: Run the parser to get a raw JSON snapshot for each docx**

Claude runs a one-off script (kept out of committed code) that uses `DocxAssessmentParser` + iterates rows, spitting out the JSON structure with `canonical = original English/mixed name from docx` and `cas = null`. Save under `Seed/*.json`.

- [ ] **Step 2: Enrich with Russian canonicals and CAS**

Claude edits each JSON:
- For substances confidently known: replace `canonical` with Russian name, move original into `aliases`, fill `cas` with the verified CAS.
- Rule: only add CAS if 100% certain from knowledge. No guessing.
- Realistic target: ~100–300 substances per file get enriched. The remaining ~700 keep original canonical and `cas: null`.

- [ ] **Step 3: Validate JSON files**

Run: `for f in app/src/ChemicalResistance/Infrastructure/Database/Seed/*.json; do jq . "$f" > /dev/null || echo "BROKEN: $f"; done`
Expected: no output.

- [ ] **Step 4: Sanity check with the importer in --dry-run mode**

Run for each file (requires Task 19 done and DDL migration applied):
```bash
cd app && bin/console coatings:chemical-resistance:import-json \
    src/ChemicalResistance/Infrastructure/Database/Seed/litatank_classic.json \
    --dry-run
```
Note: this needs a second CLI command that reads JSON instead of docx (`ImportChemicalResistanceFromJsonCommand`). Add it during this task — 30-line class that reads JSON, builds an in-memory `DocxParseResult`-equivalent, calls importer with the same behaviour.

- [ ] **Step 5: Stop for review — Task 20 output is 3 JSON files.**

---

## Phase 6 — FTS integration + seed migrations

### Task 21: SQL functions + triggers migration + integration test

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260718000002.php`
- Test: `app/tests/Functional/ChemicalResistance/Search/SearchIntegrationTest.php`

**Interfaces:**
- No PHP contract; behavioural contract:
  - Insert/update/delete on `chemical_resistance_assessment` → `coatings_coating_search.search_vector` for affected coating(s) gets segment D refreshed.
  - Update of `canonical_name`, `aliases`, `cas` on `chemical_resistance_substance` → search_vector for all affected coatings refreshed.
  - SQL function `chemical_resistance_is_suitable_grade(varchar) → bool` matches `Grade::isSuitable()`.
  - Session variable `chemical_resistance.suppress_search_recalc = 'on'` suppresses triggers within a transaction (batch mode).

- [ ] **Step 1: Find the current search_vector build logic**

Look at the migration(s) that created `coatings_coating_search` and its refresh function. Copy that function verbatim into this migration, then augment it with segment D. Keep the old function reachable in case rollback is needed.

- [ ] **Step 2: Write migration**

```php
<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FTS integration: chemical resistance substances feed into coating search_vector.';
    }

    public function up(Schema $schema): void
    {
        // 1. is_suitable_grade — single source of "R or LR" in SQL.
        $this->addSql(<<<SQL
            CREATE OR REPLACE FUNCTION chemical_resistance_is_suitable_grade(g VARCHAR)
            RETURNS BOOLEAN LANGUAGE SQL IMMUTABLE AS $$
                SELECT g = 'R' OR g = 'LR';
            $$;
        SQL);

        // 2. suitable_substance_names — all searchable names concatenated for one coating.
        $this->addSql(<<<SQL
            CREATE OR REPLACE FUNCTION chemical_resistance_suitable_substance_names(cid UUID)
            RETURNS TEXT LANGUAGE SQL STABLE AS $$
                SELECT COALESCE(string_agg(
                    sub.canonical_name
                    || ' ' || COALESCE(sub.cas, '')
                    || ' ' || COALESCE(
                        (SELECT string_agg(value, ' ')
                         FROM jsonb_array_elements_text(sub.aliases) AS value),
                        ''
                    ),
                    ' '
                ), '')
                FROM chemical_resistance_assessment a
                JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
                WHERE a.coating_id = cid
                  AND chemical_resistance_is_suitable_grade(a.grade);
            $$;
        SQL);

        // 3. REDEFINE the existing recalc function to include segment D.
        // Assumes the existing function is `recalc_coating_search_vector(cid UUID)`;
        // if it has another name, adjust here.
        $this->addSql(<<<SQL
            CREATE OR REPLACE FUNCTION recalc_coating_search_vector(cid UUID)
            RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE
                v tsvector;
                title TEXT; descr TEXT; tags TEXT; subs TEXT;
            BEGIN
                SELECT c.title, c.description, string_agg(t.title, ' ')
                  INTO title, descr, tags
                FROM coatings_coating c
                LEFT JOIN coatings_coating_tag_map m ON m.coating_id = c.id
                LEFT JOIN coatings_coating_tag t ON t.id = m.tag_id
                WHERE c.id = cid
                GROUP BY c.title, c.description;

                subs := chemical_resistance_suitable_substance_names(cid);

                v :=  setweight(to_tsvector('russian', COALESCE(title, '')), 'A')
                   || setweight(to_tsvector('russian', COALESCE(descr, '')), 'B')
                   || setweight(to_tsvector('russian', COALESCE(tags,  '')), 'C')
                   || setweight(to_tsvector('russian', COALESCE(subs,  '')), 'D');

                INSERT INTO coatings_coating_search (coating_id, search_vector)
                    VALUES (cid, v)
                    ON CONFLICT (coating_id) DO UPDATE SET search_vector = EXCLUDED.search_vector;
            END $$;
        SQL);

        // Note: adjust the SELECT above to match the actual coatings tags join table
        // (grep for the existing recalc function first). Do not blindly trust column names.

        // 4. Trigger functions.
        $this->addSql(<<<SQL
            CREATE OR REPLACE FUNCTION _cr_recalc_search_on_assessment_row()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('chemical_resistance.suppress_search_recalc', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                IF (TG_OP = 'UPDATE' AND NEW.coating_id <> OLD.coating_id) THEN
                    PERFORM recalc_coating_search_vector(OLD.coating_id);
                END IF;
                PERFORM recalc_coating_search_vector(COALESCE(NEW.coating_id, OLD.coating_id));
                RETURN COALESCE(NEW, OLD);
            END $$;
        SQL);

        $this->addSql(<<<SQL
            CREATE OR REPLACE FUNCTION _cr_recalc_search_on_substance_change()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('chemical_resistance.suppress_search_recalc', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                PERFORM recalc_coating_search_vector(a.coating_id)
                FROM chemical_resistance_assessment a
                WHERE a.substance_id = COALESCE(NEW.id, OLD.id);
                RETURN COALESCE(NEW, OLD);
            END $$;
        SQL);

        // 5. Triggers.
        $this->addSql(<<<SQL
            CREATE TRIGGER trg_recalc_search_on_assessment
            AFTER INSERT OR UPDATE OR DELETE ON chemical_resistance_assessment
            FOR EACH ROW EXECUTE FUNCTION _cr_recalc_search_on_assessment_row();
        SQL);
        $this->addSql(<<<SQL
            CREATE TRIGGER trg_recalc_search_on_substance_update
            AFTER UPDATE OF canonical_name, aliases, cas ON chemical_resistance_substance
            FOR EACH ROW EXECUTE FUNCTION _cr_recalc_search_on_substance_change();
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_recalc_search_on_substance_update ON chemical_resistance_substance');
        $this->addSql('DROP TRIGGER IF EXISTS trg_recalc_search_on_assessment ON chemical_resistance_assessment');
        $this->addSql('DROP FUNCTION IF EXISTS _cr_recalc_search_on_substance_change()');
        $this->addSql('DROP FUNCTION IF EXISTS _cr_recalc_search_on_assessment_row()');
        // recalc_coating_search_vector is left as-is (segment D produces empty text if no assessments)
        $this->addSql('DROP FUNCTION IF EXISTS chemical_resistance_suitable_substance_names(UUID)');
        $this->addSql('DROP FUNCTION IF EXISTS chemical_resistance_is_suitable_grade(VARCHAR)');
    }
}
```

**Important:** Before running the migration, grep the codebase for the current recalc function name and the tags-join table names (`coatings_coating_tag_map` and `coatings_coating_tag` are guesses). Correct the SELECT in step 3 to match reality.

- [ ] **Step 3: Apply migration**

Run: `cd app && bin/console doctrine:migrations:migrate -n`

- [ ] **Step 4: Write `SearchIntegrationTest`**

```php
final class SearchIntegrationTest extends KernelTestCase
{
    public function testGradeSyncBetweenPhpAndSql(): void
    {
        // For every Grade case, call the SQL function and assert results match ::isSuitable().
        self::bootKernel();
        $dbal = self::getContainer()->get('doctrine.dbal.default_connection');
        foreach (Grade::cases() as $g) {
            $sqlResult = $dbal->fetchOne('SELECT chemical_resistance_is_suitable_grade(?)', [$g->value]);
            self::assertSame($g->isSuitable(), (bool)$sqlResult, "Grade {$g->value} desync between PHP and SQL.");
        }
    }

    public function testSubstanceNameEndsUpInCoatingSearchVector(): void
    {
        // Given a Substance «Вода» with an alias «Water», create an Assessment R for a coating.
        // Then verify: SELECT search_vector FROM coatings_coating_search WHERE coating_id = ...
        // contains 'вода' and 'water' tokens.
    }

    public function testFtsQueryFindsCoatingByRussianAlias(): void
    {
        // Same setup, then run the CoatingFinder->fullText with search="вода" and assert coating is in results.
    }

    public function testAssessmentDeleteRemovesFromVector(): void
    {
        // Remove the Assessment, verify token no longer in the vector.
    }
}
```

- [ ] **Step 5: Run — expect pass**

Run: `cd app && vendor/bin/phpunit tests/Functional/ChemicalResistance/Search/SearchIntegrationTest.php`

- [ ] **Step 6: Stop for review.**

---

### Task 22: Three seed migrations (batch-mode-safe)

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260718000003.php` — seed litatank_classic
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260718000004.php` — seed litatank_plus
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260718000005.php` — seed litatank_standart

**Interfaces:**
- Consumes: JSON files from Task 20; DDL from Task 8; FTS triggers from Task 21.
- Produces: seeded rows in `chemical_resistance_*` tables; `coatings_coating_search.search_vector` updated once per coating at the end.

- [ ] **Step 1: Write a base seed migration**

Extract shared logic into a helper trait or base class to avoid triplication. E.g. `app/src/Shared/Infrastructure/Database/Migrations/AbstractChemicalResistanceSeedMigration.php`:

```php
abstract class AbstractChemicalResistanceSeedMigration extends AbstractMigration
{
    abstract protected function seedFileName(): string;

    public function up(Schema $schema): void
    {
        $path = __DIR__ . '/../../../ChemicalResistance/Infrastructure/Database/Seed/' . $this->seedFileName();
        if (!is_readable($path)) {
            throw new \RuntimeException("Seed file not found: $path");
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        // Fetch coating id
        $coating = $this->connection->fetchAssociative(
            'SELECT id FROM coatings_coating WHERE title = ?', [$data['coating_title']]
        );
        if ($coating === false) {
            throw new \RuntimeException("Coating «{$data['coating_title']}» must exist before seeding.");
        }
        $coatingId = $coating['id'];

        // Batch mode: suppress FTS trigger for this transaction.
        $this->addSql("SET LOCAL chemical_resistance.suppress_search_recalc = 'on'");

        // 1. Notes
        $labelToId = [];
        foreach ($data['notes'] as $n) {
            $noteId = $this->uuidV4();
            $this->addSql(
                'INSERT INTO chemical_resistance_note (id, title, description) VALUES (:id, :title, :desc)',
                ['id' => $noteId, 'title' => $n['title'], 'desc' => $n['description']]
            );
            $labelToId[$n['placeholder_label']] = $noteId;
        }

        // 2. Substances — upsert by canonical_name_key
        $substanceByCanonical = [];
        foreach ($data['substances'] as $s) {
            $key = $this->normalize($s['canonical']);
            // Try existing
            $existing = $this->connection->fetchAssociative(
                'SELECT id, aliases FROM chemical_resistance_substance WHERE canonical_name_key = ?', [$key]
            );
            if ($existing !== false) {
                $existingAliases = json_decode($existing['aliases'], true) ?: [];
                $merged = array_values(array_unique(array_merge($existingAliases, $s['aliases'] ?? [])));
                $this->addSql(
                    'UPDATE chemical_resistance_substance SET aliases = :aliases WHERE id = :id',
                    ['aliases' => json_encode($merged, JSON_UNESCAPED_UNICODE), 'id' => $existing['id']]
                );
                $substanceByCanonical[$s['canonical']] = $existing['id'];
            } else {
                $id = $this->uuidV4();
                $this->addSql(
                    'INSERT INTO chemical_resistance_substance (id, canonical_name, canonical_name_key, cas, aliases)
                     VALUES (:id, :name, :key, :cas, :aliases)',
                    [
                        'id' => $id,
                        'name' => $s['canonical'],
                        'key' => $key,
                        'cas' => $s['cas'] ?? null,
                        'aliases' => json_encode($s['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
                    ]
                );
                $substanceByCanonical[$s['canonical']] = $id;
            }
        }

        // 3. Assessments — upsert
        foreach ($data['assessments'] as $a) {
            $substanceId = $substanceByCanonical[$a['substance']]
                ?? throw new \RuntimeException("Substance ref «{$a['substance']}» not resolved.");

            $noteIds = array_map(
                fn(string $l) => $labelToId[$l] ?? throw new \RuntimeException("Note ref «$l» not resolved."),
                $a['notes'] ?? [],
            );

            $this->addSql(
                'INSERT INTO chemical_resistance_assessment
                   (id, coating_id, substance_id, grade, max_temperature_celsius, note_ids)
                 VALUES (:id, :coating, :sub, :grade, :temp, :notes)
                 ON CONFLICT (coating_id, substance_id) DO UPDATE
                   SET grade = EXCLUDED.grade,
                       max_temperature_celsius = EXCLUDED.max_temperature_celsius,
                       note_ids = EXCLUDED.note_ids',
                [
                    'id' => $this->uuidV4(),
                    'coating' => $coatingId,
                    'sub' => $substanceId,
                    'grade' => $a['grade'],
                    'temp' => $a['max_temperature'] ?? 40,
                    'notes' => json_encode($noteIds, JSON_UNESCAPED_UNICODE),
                ]
            );
        }

        // 4. Recompute search vector once for this coating.
        $this->addSql('SELECT recalc_coating_search_vector(?)', [$coatingId]);
    }

    public function down(Schema $schema): void
    {
        // Delete only this coating's assessments; substances left as-is (may be shared).
        $data = json_decode(file_get_contents(__DIR__ . '/../../../ChemicalResistance/Infrastructure/Database/Seed/' . $this->seedFileName()), true);
        $this->addSql('DELETE FROM chemical_resistance_assessment WHERE coating_id = (SELECT id FROM coatings_coating WHERE title = ?)', [$data['coating_title']]);
        // Notes seeded by this migration are safe to drop too, but tracking their ids across up/down is fiddly;
        // leaving them for admin cleanup is acceptable for v1.
    }

    protected function normalize(string $raw): string
    {
        return \App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::normalize($raw);
    }

    protected function uuidV4(): string
    {
        return \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
    }
}
```

- [ ] **Step 2: Concrete migrations**

```php
final class Version20260718000003 extends AbstractChemicalResistanceSeedMigration {
    public function getDescription(): string { return 'Seed chemical resistance: Литатанк Классик'; }
    protected function seedFileName(): string { return 'litatank_classic.json'; }
}
final class Version20260718000004 extends AbstractChemicalResistanceSeedMigration {
    public function getDescription(): string { return 'Seed chemical resistance: Литатанк Плюс'; }
    protected function seedFileName(): string { return 'litatank_plus.json'; }
}
final class Version20260718000005 extends AbstractChemicalResistanceSeedMigration {
    public function getDescription(): string { return 'Seed chemical resistance: Литатанк Стандарт'; }
    protected function seedFileName(): string { return 'litatank_standart.json'; }
}
```

Note: `AbstractMigration::addSql` doesn't accept named parameters directly for all Doctrine Migrations versions. If the project version doesn't support this, fall back to `$this->connection->executeStatement(...)` for each row and skip `addSql`. The pattern in this codebase already used in prior migrations — look at a recent seed migration for the exact style.

- [ ] **Step 3: Run migrations**

```bash
cd app && bin/console doctrine:migrations:migrate -n
```
Expected: all three seed migrations pass; `chemical_resistance_assessment` has ~3000 rows; `coatings_coating_search.search_vector` for the three Литатанк coatings has substance tokens.

- [ ] **Step 4: Manual FTS smoke check**

Run:
```bash
cd app && bin/console dbal:run-sql "
  SELECT c.title FROM coatings_coating c
  JOIN coatings_coating_search s ON s.coating_id = c.id
  WHERE s.search_vector @@ to_tsquery('russian', 'вода:*')
"
```
Expected: at least the three Литатанк coatings.

- [ ] **Step 5: Stop for review — end of Phase 6.**

---

## Phase 7 — Read side for UI

### Task 23: `ListCoatingAssessmentsQuery` + handler

**Files:**
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/ListCoatingAssessments/ListCoatingAssessmentsQuery.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/ListCoatingAssessments/ListCoatingAssessmentsQueryHandler.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/ListCoatingAssessments/CoatingAssessmentsPage.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/UseCase/Query/ListCoatingAssessments/ListCoatingAssessmentsQueryHandlerTest.php`

**Interfaces:**
- `ListCoatingAssessmentsQuery(string $coatingId, ?string $search, int $page = 1, int $pageSize = 50, ?string $highlightSubstanceId = null)`.
- Handler returns `CoatingAssessmentsPage(list<AssessmentRowDTO> $rows, int $total, int $countR, int $countLR, int $countOther)`.

- [ ] **Step 1: Extend `AssessmentRepository::paginateByCoating`**

Already declared in Task 5. Now implement in `DoctrineAssessmentRepository`:
- JOIN substance.
- If `$search` present, `WHERE substance.canonicalName ILIKE :s OR substance.canonicalNameKey ILIKE :s OR substance.aliases @> ... OR substance.cas = :s` — for a permissive filter suitable for the modal's search box.
- ORDER BY substance.canonicalName ASC.
- Pagination via Doctrine's Paginator (see `CoatingFinder::paginate` for the pattern).

Return value: `PaginationResult<Assessment>` (existing generic class in Shared).

- [ ] **Step 2: Implement query handler**

```php
final class ListCoatingAssessmentsQueryHandler
{
    public function __construct(
        private AssessmentRepository $assessments,
        private SubstanceRepository $substances,
        private EffectiveAssessmentNotes $notes,
    ) {}

    public function __invoke(ListCoatingAssessmentsQuery $q): CoatingAssessmentsPage
    {
        $cid = Uuid::fromString($q->coatingId);
        $page = $this->assessments->paginateByCoating($cid, $q->search, $q->page, $q->pageSize);

        // Bulk-load substances by id for the current page.
        $substanceIds = array_map(fn(Assessment $a) => $a->getSubstanceId()->toRfc4122(), $page->items);
        $subs = $this->substances->findAllByIds($substanceIds);
        $subById = [];
        foreach ($subs as $s) { $subById[$s->getId()] = $s; }

        $rows = [];
        foreach ($page->items as $a) {
            $s = $subById[$a->getSubstanceId()->toRfc4122()] ?? null;
            if ($s === null) continue;

            $noteViews = $this->notes->of($a);
            $rows[] = new AssessmentRowDTO(
                substanceId: $s->getId(),
                canonicalName: $s->getCanonicalName(),
                cas: $s->getCas()?->value,
                aliases: $s->getAliases()->getList(),
                grade: $a->getGrade()->value,
                maxTemperatureCelsius: $a->getMaxTemperature()->celsius,
                notes: array_map(fn(NoteView $n) => ['title' => $n->title, 'description' => $n->description, 'isSystem' => $n->isSystem], $noteViews),
            );
        }

        // Counts (across ALL assessments for the coating, not just the current page).
        $counts = $this->assessments->countByCoatingGroupedByGrade($cid);
        // counts: array<string,int>, keys = 'R','NR','LR','FS','NT'
        return new CoatingAssessmentsPage(
            rows: $rows,
            total: $page->total,
            countR: $counts['R'] ?? 0,
            countLR: $counts['LR'] ?? 0,
            countOther: ($counts['NR'] ?? 0) + ($counts['FS'] ?? 0) + ($counts['NT'] ?? 0),
        );
    }
}
```

Add `AssessmentRepository::countByCoatingGroupedByGrade(Uuid): array<string,int>` — GROUP BY grade.

- [ ] **Step 3: Write functional test**

Seed a coating with 3 assessments (R, LR, NR); call handler; assert counts and page contents.

- [ ] **Step 4: Run — expect pass**

- [ ] **Step 5: Stop for review.**

---

### Task 24: `SubstanceAutocompleteQuery` + handler

**Files:**
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/SubstanceAutocomplete/SubstanceAutocompleteQuery.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/SubstanceAutocomplete/SubstanceAutocompleteQueryHandler.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/UseCase/Query/SubstanceAutocomplete/SubstanceAutocompleteQueryHandlerTest.php`

**Interfaces:**
- `SubstanceAutocompleteQuery(string $q, int $limit = 10)`.
- Handler returns `list<SubstanceDTO>`.
- Matching: canonical ILIKE `:q%`, alias element ILIKE `:q%` (via `aliases @> :q_array` won't work for prefix — use `EXISTS (SELECT 1 FROM jsonb_array_elements_text(aliases) v WHERE v ILIKE :q_prefix)`), or exact CAS match.

- [ ] **Step 1: Implement handler with a raw SQL query for speed**

```php
final class SubstanceAutocompleteQueryHandler
{
    public function __construct(private Connection $dbal) {}

    public function __invoke(SubstanceAutocompleteQuery $q): array
    {
        $like = trim($q->q) . '%';
        $sql = "
            SELECT id::text AS id, canonical_name, cas, aliases::text AS aliases
            FROM chemical_resistance_substance
            WHERE canonical_name ILIKE :like
               OR cas = :exact
               OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(aliases) v WHERE v ILIKE :like)
            ORDER BY canonical_name ASC
            LIMIT :lim
        ";
        $rows = $this->dbal->fetchAllAssociative($sql, [
            'like' => $like, 'exact' => trim($q->q), 'lim' => $q->limit,
        ], ['lim' => \Doctrine\DBAL\ParameterType::INTEGER]);
        return array_map(fn(array $r) => new SubstanceDTO(
            id: $r['id'], canonicalName: $r['canonical_name'], cas: $r['cas'],
            aliases: json_decode($r['aliases'], true) ?: [],
        ), $rows);
    }
}
```

- [ ] **Step 2: Test** — seed a few substances, query "во" and "107-21-1" → expect matches.

- [ ] **Step 3: Stop for review.**

---

### Task 25: `MatchSubstancesForSearchQuery` + handler

**Files:**
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/MatchSubstancesForSearch/MatchSubstancesForSearchQuery.php`
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/MatchSubstancesForSearch/MatchSubstancesForSearchQueryHandler.php`
- Test: `app/tests/Functional/ChemicalResistance/Application/UseCase/Query/MatchSubstancesForSearch/MatchSubstancesForSearchQueryHandlerTest.php`

**Interfaces:**
- `MatchSubstancesForSearchQuery(list<string> $coatingIds, list<string> $searchWords)`.
- Handler returns `array<string coatingId, list<SubstanceMatchDTO>>`.

Purpose: for a batch of coatings and a set of search words, return which suitable-grade substances each coating has that match the words. Used by ListCoatingsQueryHandler to populate `CoatingDTO.matchedSubstances`.

Algorithm:
1. Fetch all suitable-grade assessments for the given coatings with joined substance data (single query).
2. For each (coating, substance) pair, check each search word via `Substance::hasName($word)` OR CAS exact-match.
3. Collect matches, dedup per coating.

- [ ] **Step 1: Implement handler**

```php
final class MatchSubstancesForSearchQueryHandler
{
    public function __construct(private Connection $dbal) {}

    /** @return array<string, list<SubstanceMatchDTO>> */
    public function __invoke(MatchSubstancesForSearchQuery $q): array
    {
        if (empty($q->coatingIds) || empty($q->searchWords)) return [];

        $sql = "
            SELECT a.coating_id::text AS cid,
                   sub.id::text AS sid,
                   sub.canonical_name,
                   sub.cas,
                   sub.aliases::text AS aliases_json
            FROM chemical_resistance_assessment a
            JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
            WHERE a.coating_id = ANY(:coatings)
              AND chemical_resistance_is_suitable_grade(a.grade)
        ";
        $rows = $this->dbal->fetchAllAssociative($sql, ['coatings' => $q->coatingIds],
            ['coatings' => \Doctrine\DBAL\ArrayParameterType::STRING]);

        $out = [];
        foreach ($rows as $r) {
            $aliases = json_decode($r['aliases_json'], true) ?: [];
            $needleWordsLower = array_map(fn(string $w) => SubstanceNameNormalizer::normalize($w), $q->searchWords);

            $matched = null;
            $canonKey = SubstanceNameNormalizer::normalize($r['canonical_name']);
            foreach ($needleWordsLower as $needle) {
                if ($needle === '') continue;
                if ($needle === $canonKey) { $matched = 'canonical'; break; }
                if ($r['cas'] !== null && $r['cas'] === $needle) { $matched = 'cas'; break; }
                foreach ($aliases as $a) {
                    if (SubstanceNameNormalizer::normalize($a) === $needle) { $matched = 'alias'; break 2; }
                }
            }
            if ($matched !== null) {
                $out[$r['cid']][] = new SubstanceMatchDTO(
                    substanceId: $r['sid'],
                    canonicalName: $r['canonical_name'],
                    matchedVia: $matched,
                );
            }
        }
        return $out;
    }
}
```

Note: `SubstanceNameNormalizer::normalize` is used symmetrically on both sides so «Water» search and «Water» alias match; alias-based prefix match («вод» → «вода») handled naturally at FTS-level, not here — this handler only confirms "yes, one of the matched substances is X" for badge purposes. Prefix-match here would be too permissive (would badge every coating whose substance starts with «в» for a search «во»); exact-normalized match is safer for badging.

- [ ] **Step 2: Test**

- [ ] **Step 3: Stop for review.**

---

### Task 26: Extend `CoatingDTO` + wire matched substances in `ListCoatingsQueryHandler`

**Files:**
- Modify: `app/src/Coatings/Application/DTO/CoatingDTO.php` — add `public readonly array $matchedSubstances = []` (list<SubstanceMatchDTO>).
- Modify: `app/src/Coatings/Application/UseCase/Query/…/ListCoatingsQueryHandler.php` (or wherever CoatingDTOs are assembled for the search page) — after fetching coatings, call `MatchSubstancesForSearchQueryHandler`.

**Interfaces:**
- Consumes: `MatchSubstancesForSearchQueryHandler`, `SubstanceMatchDTO`, existing `CoatingFinder` output.

- [ ] **Step 1: Find where CoatingDTOs are assembled**

Grep for `new CoatingDTO(` or `CoatingDTOTransformer` in `app/src/Coatings/Application/`. Modify the transformer that runs for the search list.

- [ ] **Step 2: Add field**

Add nullable/optional field `array $matchedSubstances` (default `[]`) to `CoatingDTO` constructor. If the DTO is `final readonly` — this may cascade to all callers. If so, use PHP 8 named-args and default value to keep existing calls working.

- [ ] **Step 3: Populate it**

In `ListCoatingsQueryHandler` (or equivalent), after fetching coatings and before returning:

```php
$matches = [];
if ($filter->search !== null) {
    $words = $filter->search->words();   // reuse existing SearchQuery split
    $matches = ($this->matchQuery)(new MatchSubstancesForSearchQuery(
        coatingIds: array_map(fn($c) => $c->getId(), $paginated->items),
        searchWords: $words,
    ));
}
// then when constructing each CoatingDTO:
$dto = CoatingDTOTransformer::fromEntity($c, matchedSubstances: $matches[$c->getId()] ?? []);
```

- [ ] **Step 4: Test**

Extend an existing `ListCoatingsQueryHandlerTest` (or write one) with a scenario: create coating + assessment R «Вода», search «вода», assert returned `CoatingDTO.matchedSubstances` contains «Вода».

- [ ] **Step 5: Run — expect pass**

- [ ] **Step 6: Stop for review — end of Phase 7.**

---

## Phase 8 — UI: search results

### Task 27: List card — «✓ Стойкое к» badges

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_coating_cards_batch.html.twig`
- Create: `app/assets/styles/components/coating-substance-badges.css`
- Modify: `app/assets/styles/app.css` — `@import` the new component.

**Interfaces:**
- Consumes: `CoatingDTO.matchedSubstances: list<SubstanceMatchDTO>`.

- [ ] **Step 1: Add badge block in the card**

In `_coating_cards_batch.html.twig`, right after the `<p class="mb-1 small text-muted coating-card-desc">…description…</p>` and before the tags block, insert:

```twig
{% if coating.matchedSubstances is defined and coating.matchedSubstances|length > 0 %}
    {% set matches = coating.matchedSubstances %}
    {% set visible = matches|slice(0, 3) %}
    {% set hidden  = matches|length - visible|length %}
    <div class="d-flex flex-wrap gap-1 align-items-center mb-1 coating-match-substances">
        <span class="small text-success-emphasis fw-medium me-1">✓ Стойкое к:</span>
        {% for m in visible %}
            <a href="#"
               data-bs-toggle="modal"
               data-bs-target="#coatingPreview-{{ coating.id }}"
               data-controller="substance-badge"
               data-substance-badge-id-value="{{ m.substanceId }}"
               class="badge text-bg-success bg-opacity-25 text-body-emphasis fw-normal text-decoration-none">
                {{ m.canonicalName }}
            </a>
        {% endfor %}
        {% if hidden > 0 %}
            <span class="badge text-bg-light fw-normal">+{{ hidden }} ещё</span>
        {% endif %}
    </div>
{% endif %}
```

- [ ] **Step 2: CSS component file**

```css
/* app/assets/styles/components/coating-substance-badges.css */
.coating-match-substances .badge {
    font-size: 0.75rem;
}
.coating-match-substances a.badge {
    cursor: pointer;
}
```

Add `@import "./components/coating-substance-badges.css";` to `app/assets/styles/app.css`.

- [ ] **Step 3: Stimulus controller for badge → modal deep-link**

Create `app/assets/controllers/substance_badge_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { id: String };

    connect() {
        this.element.addEventListener('click', (e) => {
            // Store the substance id in a data attr on the modal itself so the modal's
            // own controller can read it and scroll to the row.
            const modal = document.getElementById(this.element.getAttribute('data-bs-target').substring(1));
            if (modal) modal.setAttribute('data-highlight-substance-id', this.idValue);
        });
    }
}
```

The `controllers.json` autoloader should pick it up automatically. If not — add to `assets/controllers.json` manually.

- [ ] **Step 4: Rebuild assets and eyeball in browser**

Run: `cd app && yarn dev`
Open the search page, search «вода» (once seed migrations are applied). Verify badges appear on matching coatings.

- [ ] **Step 5: Stop for review.**

---

### Task 28: Modal — «Химическая стойкость» section (first page)

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_coating_cards_batch.html.twig` — insert new section after «Время высыхания».
- Create: Twig macro/partial `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_chem_resistance_section.html.twig` — the section body.
- Modify: whichever handler builds `CoatingDTO` for modal preview — needs to include a preloaded first page of assessments (or fetch inside the twig via a service call; prefer preload for SSR-only rendering).

**Interfaces:**
- Consumes: `ListCoatingAssessmentsQueryHandler`, `AssessmentRowDTO`, `SystemNotes`.
- Produces: a rendered section with:
  - three summary counters (R / LR / other),
  - client-side search input (Stimulus, wired in Task 29),
  - table of first 50 rows,
  - «Показать все N» button (Task 29),
  - «Общие условия» block listing SystemNotes below the table.

- [ ] **Step 1: Preload first page of assessments in the list handler**

In `ListCoatingsQueryHandler` (already touched in Task 26), for each coating in the current page, call `ListCoatingAssessmentsQueryHandler` with `page=1, pageSize=50, search=null`. Attach result as `coating.chemResistancePage: CoatingAssessmentsPage`.

Add field `?CoatingAssessmentsPage $chemResistancePage = null` to `CoatingDTO` with default null.

Perf note: this preloads for the current page of coatings (typically 20). Each preload is one paginated SELECT. Acceptable for MVP; if too slow, move to lazy fetch on modal open (Task 29's partial endpoint).

- [ ] **Step 2: Create the section partial**

```twig
{# _chem_resistance_section.html.twig
   Params:
     coating       — CoatingDTO
     assessments   — CoatingAssessmentsPage (or null → don't render section) #}
{% if assessments is not null %}
<div class="p-3 mb-3 rounded-3 bg-body-tertiary"
     data-controller="chem-resistance"
     data-chem-resistance-coating-id-value="{{ coating.id }}"
     data-chem-resistance-total-value="{{ assessments.total }}">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0">Химическая стойкость</h6>
        <div class="small text-muted">
            <span class="badge text-bg-success bg-opacity-25 text-body-emphasis">✓ {{ assessments.countR }}</span>
            <span class="badge text-bg-warning bg-opacity-25 text-body-emphasis">⚠ {{ assessments.countLR }}</span>
            <span class="badge text-bg-danger bg-opacity-25 text-body-emphasis">✗ {{ assessments.countOther }}</span>
        </div>
    </div>

    <div class="mb-2">
        <input type="search" class="form-control form-control-sm"
               placeholder="Поиск по веществу…"
               data-chem-resistance-target="search"
               data-action="input->chem-resistance#onSearchInput">
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th class="text-muted fw-normal">Вещество</th>
                    <th class="text-muted fw-normal text-center">Оценка</th>
                    <th class="text-muted fw-normal text-center">Макс. T</th>
                    <th class="text-muted fw-normal">Примечания</th>
                </tr>
            </thead>
            <tbody data-chem-resistance-target="tbody">
                {% for row in assessments.rows %}
                    {% include 'admin/coating/coating/_chem_resistance_row.html.twig' with { row: row } %}
                {% endfor %}
            </tbody>
        </table>
    </div>

    {% if assessments.total > assessments.rows|length %}
        <button type="button" class="btn btn-outline-secondary btn-sm mt-2"
                data-chem-resistance-target="loadAllBtn"
                data-action="chem-resistance#loadAll">
            Показать все {{ assessments.total }}
        </button>
    {% endif %}

    {% set systemNotes = system_notes() %}
    {% if systemNotes|length > 0 %}
        <div class="mt-3 pt-3 border-top">
            <div class="small text-muted fw-medium mb-1">Общие условия</div>
            {% for n in systemNotes %}
                <div class="small text-body-secondary" title="{{ n.title }}">
                    <i class="bi bi-info-circle"></i> {{ n.description }}
                </div>
            {% endfor %}
        </div>
    {% endif %}
</div>
{% endif %}
```

`system_notes()` — a new Twig function returning `SystemNotes::all()`. Register it via a Twig extension:

```php
// app/src/ChemicalResistance/Infrastructure/Twig/ChemicalResistanceExtension.php
final class ChemicalResistanceExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('system_notes', fn() => \App\ChemicalResistance\Domain\Service\SystemNotes::all())];
    }
}
```
Autoregistered as a service.

- [ ] **Step 3: Row partial**

```twig
{# _chem_resistance_row.html.twig — params: row (AssessmentRowDTO) #}
<tr data-substance-id="{{ row.substanceId }}"
    data-search-key="{{ (row.canonicalName ~ ' ' ~ (row.cas ?? '') ~ ' ' ~ row.aliases|join(' '))|lower }}">
    <td>
        <div>{{ row.canonicalName }}</div>
        {% if row.cas or row.aliases|length > 0 %}
            <div class="small text-muted">
                {% if row.cas %}CAS {{ row.cas }}{% if row.aliases|length > 0 %} · {% endif %}{% endif %}
                {% if row.aliases|length > 0 %}{{ row.aliases|first }}{% endif %}
            </div>
        {% endif %}
    </td>
    <td class="text-center">
        {% set gradeClass = {
            'R':'text-bg-success','LR':'text-bg-warning','NR':'text-bg-danger',
            'FS':'text-bg-secondary','NT':'text-bg-secondary'
        }[row.grade] %}
        <span class="badge {{ gradeClass }}">{{ row.grade }}</span>
    </td>
    <td class="text-center text-nowrap">{{ row.maxTemperatureCelsius }}°C</td>
    <td>
        {% for n in row.notes if not n.isSystem %}
            <span class="badge text-bg-light fw-normal me-1"
                  data-bs-toggle="tooltip"
                  title="{{ n.title }}: {{ n.description }}">
                ⓘ {{ n.title }}
            </span>
        {% endfor %}
        {% if row.notes|filter(n => not n.isSystem)|length == 0 %}
            <span class="text-muted">—</span>
        {% endif %}
    </td>
</tr>
```

- [ ] **Step 4: Insert section into modal**

In `_coating_cards_batch.html.twig`, right after the «Время высыхания» section, add:

```twig
{% include 'admin/coating/coating/_chem_resistance_section.html.twig'
    with { coating: coating, assessments: coating.chemResistancePage } %}
```

- [ ] **Step 5: Rebuild + eyeball**

Run: `cd app && yarn dev`
Open modal for a Литатанк coating, see the section with the summary badges and first 50 rows.

- [ ] **Step 6: Stop for review.**

---

### Task 29: Partial endpoint + Stimulus for pagination/search inside modal

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Controller/Coating/AssessmentsPartialAction.php` — GET route `/cabinet/coatings/{coatingId}/chem-resistance/partial?search=&page=&highlight=` → returns HTML fragment `<tbody>...</tbody>`.
- Create: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_chem_resistance_rows_only.html.twig` — just the `<tr>` fragments (loop over rows using `_chem_resistance_row.html.twig`).
- Create: `app/assets/controllers/chem_resistance_controller.js`.

**Interfaces:**
- Endpoint returns HTML fragment (rows only), driven by same query handler.
- Stimulus controller:
  - `onSearchInput` — debounce 200ms, fetch `partial?search=...&page=1`, replace `<tbody>` innerHTML.
  - `loadAll` — fetch `partial?search=<current>&page=1&pageSize=<total>`, replace `<tbody>`, hide button.

- [ ] **Step 1: Controller action**

```php
#[Route('/cabinet/coatings/{coatingId}/chem-resistance/partial', name: 'app_cabinet_coating_chem_resistance_partial', methods: ['GET'])]
final class AssessmentsPartialAction
{
    public function __construct(private ListCoatingAssessmentsQueryHandler $handler, private Environment $twig) {}

    public function __invoke(string $coatingId, Request $req): Response
    {
        $page = $req->query->getInt('page', 1);
        $pageSize = $req->query->getInt('pageSize', 50);
        $search = $req->query->get('search') ?: null;
        $highlight = $req->query->get('highlight') ?: null;

        $result = ($this->handler)(new ListCoatingAssessmentsQuery(
            coatingId: $coatingId, search: $search, page: $page, pageSize: $pageSize,
            highlightSubstanceId: $highlight,
        ));

        return new Response($this->twig->render(
            'admin/coating/coating/_chem_resistance_rows_only.html.twig',
            ['rows' => $result->rows, 'highlight' => $highlight],
        ));
    }
}
```

- [ ] **Step 2: Rows-only partial**

```twig
{% for row in rows %}
    {% include 'admin/coating/coating/_chem_resistance_row.html.twig' with { row: row } %}
{% endfor %}
```

- [ ] **Step 3: Stimulus controller**

```js
// app/assets/controllers/chem_resistance_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { coatingId: String, total: Number };
    static targets = ['tbody', 'search', 'loadAllBtn'];

    connect() {
        this.debounceTimer = null;
        // Deep-link highlight: read data-highlight-substance-id from the enclosing modal.
        const modal = this.element.closest('.modal');
        const highlight = modal ? modal.getAttribute('data-highlight-substance-id') : null;
        if (highlight) this.loadForHighlight(highlight);
    }

    onSearchInput(event) {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => this.fetch(event.target.value, 1, 50), 200);
    }

    loadAll() {
        this.fetch(this.searchTarget.value || '', 1, this.totalValue).then(() => {
            if (this.hasLoadAllBtnTarget) this.loadAllBtnTarget.style.display = 'none';
        });
    }

    async loadForHighlight(substanceId) {
        // Simple approach: load everything, then scroll to substanceId.
        await this.fetch('', 1, this.totalValue, substanceId);
        if (this.hasLoadAllBtnTarget) this.loadAllBtnTarget.style.display = 'none';
        const row = this.tbodyTarget.querySelector(`tr[data-substance-id="${substanceId}"]`);
        if (row) {
            row.classList.add('table-warning');
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    async fetch(search, page, pageSize, highlight = null) {
        const params = new URLSearchParams({ page, pageSize });
        if (search) params.set('search', search);
        if (highlight) params.set('highlight', highlight);
        const url = `/cabinet/coatings/${this.coatingIdValue}/chem-resistance/partial?${params}`;
        const resp = await fetch(url, { headers: { Accept: 'text/html' } });
        if (!resp.ok) return;
        this.tbodyTarget.innerHTML = await resp.text();
        // Re-init tooltips on freshly rendered content.
        if (window.bootstrap && window.bootstrap.Tooltip) {
            this.tbodyTarget.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new window.bootstrap.Tooltip(el));
        }
    }
}
```

- [ ] **Step 4: Rebuild and manual test**

Run: `cd app && yarn dev`
Open modal, type «вода» in the section's search — table filters. Click «Показать все» — full table loads.

- [ ] **Step 5: Stop for review.**

---

### Task 30: Deep-link `?substance=<uuid>` highlighting

**Files:**
- Modify: `app/assets/controllers/chem_resistance_controller.js` — the highlight-on-connect path already exists (Task 29 step 3); ensure it fires when the modal opens.
- Modify: `_coating_cards_batch.html.twig` — the badge in the list card already writes `data-highlight-substance-id` to the modal element on click (Task 27).

Bootstrap modal `show.bs.modal` event fires after `chem-resistance` controller `connect`. Adjust:

- [ ] **Step 1: Listen for modal show and re-trigger highlight**

Update controller:

```js
connect() {
    this.debounceTimer = null;
    const modal = this.element.closest('.modal');
    if (modal) {
        modal.addEventListener('shown.bs.modal', () => {
            const highlight = modal.getAttribute('data-highlight-substance-id');
            if (highlight) this.loadForHighlight(highlight);
        });
    }
}
```

- [ ] **Step 2: Manual test**

Search «вода», see badge on a Литатанк card, click it → modal opens, section scrolls to «Вода» row and highlights it yellow.

- [ ] **Step 3: Stop for review — end of Phase 8.**

---

## Phase 9 — Admin UI

Admin pages follow the existing pattern in `app/src/Coatings/Infrastructure/Controller/CoatingTag/` and `Manufacturer/`. Each aggregate gets List / Create / Update / Delete actions with a form. Twig templates live under `app/src/Shared/Infrastructure/Templates/admin/chemical_resistance/`.

Because these follow existing well-worn patterns, tasks are described at a slightly higher level. Reviewers should reject if the new admin visibly diverges from the CoatingTag/Manufacturer style (URL scheme, table columns, form CSS).

### Task 31: Substance admin (list/create/update/delete)

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Controller/Substance/{ListAction,CreateAction,UpdateAction,DeleteAction}.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Mapper/SubstanceMapper.php` — form ↔ DTO (pure shape mapping, no business rules; see CLAUDE.md).
- Create: templates `app/src/Shared/Infrastructure/Templates/admin/chemical_resistance/substance/{index,form}.html.twig`.

**Interfaces:**
- Routes:
  - `GET /cabinet/chemical-resistance/substances` — list with search box (delegates to `SubstanceAutocompleteQuery` or a paginated variant).
  - `GET /cabinet/chemical-resistance/substances/create` + POST → CreateSubstanceCommand.
  - `GET /cabinet/chemical-resistance/substances/{id}/update` + POST → UpdateSubstanceCommand.
  - `POST /cabinet/chemical-resistance/substances/{id}/delete` → DeleteSubstanceCommand.

- [ ] **Step 1: Look at existing pattern**

Read `app/src/Coatings/Infrastructure/Controller/CoatingTag/CreateAction.php` and `UpdateAction.php`. Mirror the controller structure, form field validation (`Assert\NotBlank`, `Assert\Length`), and error-rendering via `<div class="alert alert-danger">`.

- [ ] **Step 2: SubstanceMapper**

```php
final class SubstanceMapper
{
    /** POST → DTO */
    public function buildDtoFromInputData(?string $id, array $input): SubstanceDTO
    {
        return new SubstanceDTO(
            id: $id,
            canonicalName: (string)($input['canonicalName'] ?? ''),
            cas: ($input['cas'] ?? '') !== '' ? (string)$input['cas'] : null,
            aliases: array_values(array_filter(array_map('trim',
                explode("\n", (string)($input['aliasesText'] ?? ''))
            ))),
        );
    }

    /** DTO → form input */
    public function buildInputDataFromDto(SubstanceDTO $dto): array
    {
        return [
            'canonicalName' => $dto->canonicalName,
            'cas' => $dto->cas ?? '',
            'aliasesText' => implode("\n", $dto->aliases),
        ];
    }

    /** Symfony structural validation collection */
    public function getValidationCollection(): Assert\Collection { /* NotBlank on canonicalName, Length ≤200, Regex on CAS if present, etc. */ }
}
```

- [ ] **Step 3: Controllers**

Each action is thin: read form → mapper → command → catch AppException → render form with error. Follow the existing `CoatingTag` pattern strictly.

- [ ] **Step 4: Twig templates**

`index.html.twig` — table with columns: Название | CAS | Aliases (первые 3 + «…») | Использований (count from `AssessmentRepository::countBySubstance`) | Actions.
`form.html.twig` — inputs for canonical, CAS, textarea for aliases (one per line).

- [ ] **Step 5: Manual smoke test**

Create → update → delete a test Substance via the admin UI.

- [ ] **Step 6: Stop for review.**

---

### Task 32: Note admin

**Files:**
- Same shape as Task 31, under `app/src/ChemicalResistance/Infrastructure/Controller/Note/…` and `templates/admin/chemical_resistance/note/…`.

**Interfaces:**
- `GET/POST /cabinet/chemical-resistance/notes[/…]` for list/create/update/delete.
- Delete is blocked via `DeleteNoteCommandHandler` (Task 12) when referenced.

- [ ] **Step 1: Copy Substance admin structure, adjust for Note (title/description).**

- [ ] **Step 2: Manual smoke.**

- [ ] **Step 3: Stop for review.**

---

### Task 33: Coating assessments page (inline edit)

**Files:**
- Create: `app/src/ChemicalResistance/Infrastructure/Controller/Coating/AssessmentsPageAction.php` — `/cabinet/coatings/{coatingId}/chem-resistance` full-page.
- Create: `app/src/ChemicalResistance/Infrastructure/Controller/Assessment/{UpdateAction,DeleteAction,CreateAction}.php` — for inline form submits.
- Create: template `app/src/Shared/Infrastructure/Templates/admin/coating/coating/chem_resistance_edit.html.twig`.
- Modify: coating edit page — add button «Химстойкость →» linking to the new page.

**Interfaces:**
- The page renders the same table used in modal (Task 28), plus inline edit forms per row and a form to add a new assessment (autocomplete for substance, select for grade, number for maxTemp, multi-select for notes).

- [ ] **Step 1: Page action — reuse ListCoatingAssessmentsQueryHandler**

Render the full first-page table with edit controls on every row.

- [ ] **Step 2: Inline update — small partial-form per row**

Each row is a `<form>` posting to `POST /cabinet/coatings/{coatingId}/chem-resistance/{assessmentId}/update`. Uses `UpdateAssessmentCommand`.

- [ ] **Step 3: Add-assessment form at bottom of table**

Autocomplete input for substance (Stimulus controller hitting `SubstanceAutocompleteQuery` endpoint — add a JSON route for the autocomplete). On submit → `CreateAssessmentCommand`.

- [ ] **Step 4: Manual smoke test**

Add / edit / delete an assessment via the admin page. Verify the coating modal (Task 28) shows the change after reload.

- [ ] **Step 5: Stop for review — end of Phase 9.**

---

## Phase 10 — Ship

### Task 34: Final smoke test + cleanup

- [ ] **Step 1: Full test suite**

Run: `cd app && vendor/bin/phpunit`
Expected: all green. Fix any regressions from Phases 7–9 that touched shared code (`CoatingDTO`, list templates).

- [ ] **Step 2: Rebuild assets**

Run: `cd app && yarn dev`

- [ ] **Step 3: Manual golden-path smoke test in the browser**

- Log in as an admin.
- Search: «вода» — expect Литатанк coatings with «✓ Стойкое к: Вода» badges.
- Search: «7732-18-5» — same Литатанк coatings.
- Search: «Литатанк Классик» — the coating comes up ranked by title match, not by substance match.
- Click a badge on a card — modal opens, «Химическая стойкость» section auto-highlights and scrolls to «Вода».
- In the modal's section: type «этанол» — table filters live.
- Click «Показать все» — full table loads.
- Navigate to admin → Substances — add a test substance, verify it appears in search.
- Navigate to a Литатанк coating's admin edit page → «Химстойкость →» — add a fake assessment, save, verify it appears in the modal after page reload.

- [ ] **Step 4: Cleanup**

Search for and remove any `dd()`, `var_dump()`, commented-out blocks introduced during work. Delete `.DS_Store` files if any were staged.

- [ ] **Step 5: Stop for final review.**

---

## Notes for the implementer

- **Task 20** (JSON seeding) is intended to be delivered by the human working with claude. If you (the implementer) find the JSON files already committed, skip to Task 21. If they're missing, ping the project owner — do NOT hand-fabricate CAS values; wrong CAS in the catalog is worse than absent CAS.
- **`makeSpec()` on repositories** (referenced in Tasks 9, 10, 13, 15): if you don't want to add this convenience method, inline the specification construction at the call site. It's just three lines.
- **Search-vector recalc function name and tags table names in Task 21 step 2** are conjectural. Grep for the existing function/tables before running the migration; the correction is a one-minute fix but blindly applying the SQL as written will fail.
- **Twig `system_notes()`** function (Task 28 step 2) needs a Twig extension registered as a service — Symfony autoconfigure should pick it up if the class is under `App\...\Infrastructure\Twig\`, but double-check with `bin/console debug:twig`.
- **Grade sync test** (Task 21 step 4) is the safety net for the PHP-vs-SQL rule duplication. Don't skip it.
- **Repository methods added incrementally.** Task 5 declares the base `AssessmentRepository` interface, but later tasks add methods:
  - Task 12: `countAssessmentsWithNoteId(string $noteId): int` (native SQL, `note_ids @> :id::jsonb`).
  - Task 23: `countByCoatingGroupedByGrade(Uuid $coatingId): array<string,int>` (JPQL GROUP BY).
  - Task 31: `countBySubstance(Uuid $substanceId): int` (for the Substance admin list column).
  Same for `SubstanceLookup::findByNormalizedName(string): ?Substance` added in Task 18. Add each method — interface + Doctrine impl — as its consumer task requires it, then re-run functional tests to catch signature drift.
- **Do NOT run `git commit` unless the human explicitly asks.** Sub-agents implementing individual tasks: mark tasks complete and stop; don't chain commits together. The human curates the git history.
