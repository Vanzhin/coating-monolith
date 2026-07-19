<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueCasSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueSubstanceNameSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\InvalidArgumentException;

final class SubstanceTest extends TestCase
{
    private function noopSpec(): SubstanceSpecification
    {
        $repo = $this->createMock(SubstanceRepositoryInterface::class);
        $repo->method('findByCanonicalNameKey')->willReturn(null);
        $repo->method('findByCas')->willReturn(null);

        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($repo),
            new UniqueCasSpecification($repo),
        );
    }

    private function conflictingByNameSpec(string $conflictCanonical): SubstanceSpecification
    {
        $repo = $this->createMock(SubstanceRepositoryInterface::class);
        $conflictingId = Uuid::v4();
        $repo->method('findByCanonicalNameKey')->willReturn(
            new Substance($conflictingId, $conflictCanonical, null, new StringCollection(),
                $this->noopSpec())
        );
        $repo->method('findByCas')->willReturn(null);

        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($repo),
            new UniqueCasSpecification($repo),
        );
    }

    private function conflictingByCasSpec(CasNumber $conflictCas): SubstanceSpecification
    {
        $repo = $this->createMock(SubstanceRepositoryInterface::class);
        $conflictingId = Uuid::v4();
        $repo->method('findByCanonicalNameKey')->willReturn(null);
        $repo->method('findByCas')->willReturn(
            new Substance($conflictingId, 'Dummy', $conflictCas, new StringCollection(),
                $this->noopSpec())
        );

        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($repo),
            new UniqueCasSpecification($repo),
        );
    }

    public function test_construct_russian_canonical(): void
    {
        $s = new Substance(Uuid::v4(), 'Этиленгликоль', CasNumber::fromString('107-21-1'),
            new StringCollection('Ethylene glycol', '1,2-Ethanediol'), $this->noopSpec());
        self::assertSame('Этиленгликоль', $s->getCanonicalName());
        self::assertSame('107-21-1', (string) $s->getCas());
        self::assertSame(['Ethylene glycol', '1,2-Ethanediol'], $s->getAliases()->getList());
    }

    public function test_has_name_finds_canonical(): void
    {
        $s = new Substance(Uuid::v4(), 'Этиленгликоль', null, new StringCollection(), $this->noopSpec());
        self::assertTrue($s->hasName('этиленгликоль'));
        self::assertTrue($s->hasName(' Этиленгликоль '));
        self::assertFalse($s->hasName('Водоглицерин'));
    }

    public function test_has_name_finds_aliases(): void
    {
        $s = new Substance(Uuid::v4(), 'Этиленгликоль', null,
            new StringCollection('Ethylene glycol'), $this->noopSpec());
        self::assertTrue($s->hasName('ethylene-glycol'));
        self::assertTrue($s->hasName('ETHYLENE GLYCOL'));
    }

    public function test_add_alias_idempotent(): void
    {
        $s = new Substance(Uuid::v4(), 'Water', null,
            new StringCollection('Вода'), $this->noopSpec());
        $s->addAlias('Вода');        // same
        $s->addAlias(' вода ');       // normalizes to same
        self::assertSame(['Вода'], $s->getAliases()->getList());
    }

    public function test_add_alias_same_as_canonical_is_noop(): void
    {
        $s = new Substance(Uuid::v4(), 'Water', null, new StringCollection(), $this->noopSpec());
        $s->addAlias('WATER');
        self::assertSame([], $s->getAliases()->getList());
    }

    public function test_canonical_name_key_reflects_rename(): void
    {
        $s = new Substance(Uuid::v4(), 'Water', null, new StringCollection(), $this->noopSpec());
        $keyBefore = $s->getCanonicalNameKey();
        $s->setCanonicalName('Вода');
        self::assertNotSame($keyBefore, $s->getCanonicalNameKey());
    }

    public function test_empty_canonical_name_throws(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Название вещества не может быть пустым.');
        new Substance(Uuid::v4(), '', null, new StringCollection(), $this->noopSpec());
    }

    public function test_whitespace_only_canonical_name_throws(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Название вещества не может быть пустым.');
        new Substance(Uuid::v4(), '   ', null, new StringCollection(), $this->noopSpec());
    }

    public function test_canonical_name_too_long_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $longName = str_repeat('a', 201);
        new Substance(Uuid::v4(), $longName, null, new StringCollection(), $this->noopSpec());
    }

    public function test_alias_too_long_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $longAlias = str_repeat('b', 201);
        new Substance(Uuid::v4(), 'Water', null, new StringCollection($longAlias), $this->noopSpec());
    }

    public function test_unique_substance_name_specification_conflict_throws(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Вещество «Water» уже существует в справочнике.');
        new Substance(Uuid::v4(), 'Water', null, new StringCollection(),
            $this->conflictingByNameSpec('Water'));
    }

    public function test_unique_cas_specification_conflict_throws(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('CAS-номер «107-21-1» уже используется другим веществом в справочнике.');
        $cas = CasNumber::fromString('107-21-1');
        new Substance(Uuid::v4(), 'Glycol', $cas, new StringCollection(),
            $this->conflictingByCasSpec($cas));
    }

    public function test_get_searchable_names_includes_canonical_aliases_and_cas(): void
    {
        $cas = CasNumber::fromString('107-21-1');
        $s = new Substance(Uuid::v4(), 'Этиленгликоль', $cas,
            new StringCollection('Ethylene glycol', '1,2-Ethanediol'), $this->noopSpec());
        $searchable = $s->getSearchableNames()->getList();
        self::assertSame([
            'Этиленгликоль',
            'Ethylene glycol',
            '1,2-Ethanediol',
            '107-21-1',
        ], $searchable);
    }
}
