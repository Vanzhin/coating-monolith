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
