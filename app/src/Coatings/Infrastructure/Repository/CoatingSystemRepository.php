<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemRepository implements CoatingSystemRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

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

    public function countUsingSurfaceTreatment(string $treatmentId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(cs.id)')
            ->from(CoatingSystem::class, 'cs')
            ->where('cs.surfaceTreatment = :tid')
            ->setParameter('tid', $treatmentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByLayerCoatingId(string $coatingId): array
    {
        return $this->em->createQueryBuilder()
            ->select('cs')
            ->from(CoatingSystem::class, 'cs')
            ->innerJoin('cs.layers', 'l')
            ->where('l.coating = :coatingId')
            ->setParameter('coatingId', $coatingId)
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(CoatingSystem::class, 's')
            ->leftJoin('s.layers', 'l')
            ->leftJoin('l.coating', 'c')
            ->leftJoin('l.color', 'lc')
            ->addSelect('l')
            ->addSelect('c')
            ->addSelect('lc')
            ->getQuery()
            ->getResult();
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        $systems = $this->em->createQueryBuilder()
            ->select('cs')
            ->from(CoatingSystem::class, 'cs')
            ->leftJoin('cs.layers', 'l')->addSelect('l')
            ->leftJoin('l.coating', 'c')->addSelect('c')
            ->leftJoin('l.color', 'lc')->addSelect('lc')
            ->leftJoin('c.manufacturer', 'm')->addSelect('m')
            ->leftJoin('cs.tags', 't')->addSelect('t')
            ->where('cs.id IN (:ids)')
            ->setParameter('ids', $ids->getList())
            ->getQuery()
            ->getResult();

        // Восстановить порядок $ids
        $byId = [];
        foreach ($systems as $system) {
            $byId[$system->getId()] = $system;
        }
        $ordered = [];
        foreach ($ids->getList() as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
