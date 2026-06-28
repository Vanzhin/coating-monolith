<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Infrastructure\Search\CoatingFinder;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CoatingRepository extends ServiceEntityRepository implements CoatingRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CoatingFinder $finder,
    ) {
        parent::__construct($registry, Coating::class);
    }

    public function add(Coating $coating): void
    {
        $this->getEntityManager()->persist($coating);
        $this->getEntityManager()->flush();
    }

    public function remove(Coating $coating): void
    {
        $this->getEntityManager()->remove($coating);
        $this->getEntityManager()->flush();
    }

    public function findByFilter(CoatingsFilter $filter): PaginationResult
    {
        $result = $this->finder->fullText($filter);
        if ($result->total > 0) {
            return $result;
        }

        // Fuzzy-fallback включается ТОЛЬКО для single-word запросов (опечатки
        // вида «бетано» вместо «бетон»). На multi-word fuzzy сравнивает
        // ВСЮ строку как substring c title/description, теряет per-word
        // AND-семантику и игнорирует теги — это даёт «похожее но не то».
        // Пустой результат лучше, чем нерелевантные хиты.
        if ($filter->search !== null && $this->isSingleWordQuery($filter->search)) {
            return $this->finder->fuzzyTitle($filter);
        }

        return $result;
    }

    private function isSingleWordQuery(string $query): bool
    {
        // Тот же splitter что и в CoatingFinder::buildPrefixTsQuery —
        // консистентность определения «слова» между обоими путями.
        $words = preg_split('/[\s\-.,;]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) && count($words) === 1;
    }

    public function findOneById(string $id): ?Coating
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByTitle(string $title): ?Coating
    {
        return $this->findOneBy(['title' => $title]);
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var array<string, Coating> $byId */
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $coating) {
            $byId[$coating->getId()] = $coating;
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
