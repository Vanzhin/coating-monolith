<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstancesFilter;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Триграммный fuzzy-fallback в SubstanceRepository::findByFilter: опечатка в
 * названии вещества («этонол») должна всё равно находить «Этанол», но точный
 * ILIKE-путь (фаза 1) и мусорный ввод не должны ломаться fuzzy-фазой.
 */
final class SubstanceRepositoryFuzzyTest extends KernelTestCase
{
    private SubstanceRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->repo = $c->get(SubstanceRepository::class);
        $this->em = $c->get(EntityManagerInterface::class);

        $spec = $c->get(SubstanceSpecification::class);
        $this->repo->add(new Substance(Uuid::v4(), 'Этанол', null, new StringCollection(), $spec));
        $this->repo->add(new Substance(Uuid::v4(), 'Ацетон', null, new StringCollection(), $spec));
        $this->em->clear();
    }

    public function test_fuzzy_recovers_single_letter_typo(): void
    {
        // «этонол» (опечатка) — точного ILIKE-хита нет, должен сработать триграммный fallback.
        $res = $this->repo->findByFilter(new SubstancesFilter(search: 'этонол'));

        self::assertSame('Этанол', $res->items[0]->canonicalName);
    }

    public function test_exact_substring_still_works_without_fuzzy(): void
    {
        $res = $this->repo->findByFilter(new SubstancesFilter(search: 'этан'));

        self::assertSame('Этанол', $res->items[0]->canonicalName);
    }

    public function test_garbage_input_returns_empty_not_noise(): void
    {
        $res = $this->repo->findByFilter(new SubstancesFilter(search: 'zzzzzz'));

        self::assertSame(0, $res->total);
    }
}
