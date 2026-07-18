<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueCasSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueSubstanceNameSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineSubstanceRepository implements SubstanceRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function makeSpec(): SubstanceSpecification
    {
        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($this),
            new UniqueCasSpecification($this),
        );
    }

    public function save(Substance $s): void
    {
        $this->em->persist($s);
        $this->em->flush();
    }

    public function remove(Substance $s): void
    {
        $this->em->remove($s);
        $this->em->flush();
    }

    public function find(Uuid $id): ?Substance
    {
        $s = $this->em->find(Substance::class, $id);
        if ($s !== null) {
            $s->setSpecification($this->makeSpec());
        }
        return $s;
    }

    public function findByCanonicalNameKey(string $key): ?Substance
    {
        /** @var ?Substance $s */
        $s = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Substance::class, 's')
            ->where('s.canonicalNameKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();

        if ($s !== null) {
            $s->setSpecification($this->makeSpec());
        }
        return $s;
    }

    public function findByCas(CasNumber $cas): ?Substance
    {
        /** @var ?Substance $s */
        $s = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Substance::class, 's')
            ->where('s.cas = :cas')
            ->setParameter('cas', $cas->value)
            ->getQuery()
            ->getOneOrNullResult();

        if ($s !== null) {
            $s->setSpecification($this->makeSpec());
        }
        return $s;
    }

    /**
     * @param list<string> $ids
     * @return list<Substance>
     */
    public function findAllByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Substance> $substances */
        $substances = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Substance::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $spec = $this->makeSpec();
        /** @var array<string, Substance> $byId */
        $byId = [];
        foreach ($substances as $s) {
            $s->setSpecification($spec);
            $byId[$s->getId()] = $s;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }
}
