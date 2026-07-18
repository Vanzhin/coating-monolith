<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentNotesConsistencyValidator;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\UniqueCoatingSubstanceAssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final class DoctrineAssessmentRepository implements AssessmentRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteRepository $notes,
    ) {}

    public function makeSpec(): AssessmentSpecification
    {
        return new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($this),
            new AssessmentNotesConsistencyValidator(),
        );
    }

    private function reinject(Assessment $a): void
    {
        $a->setSpecification($this->makeSpec());
        $a->setNotesRepositoryForConsistency($this->notes);
    }

    public function save(Assessment $a): void
    {
        $this->em->persist($a);
        $this->em->flush();
    }

    public function remove(Assessment $a): void
    {
        $this->em->remove($a);
        $this->em->flush();
    }

    public function find(Uuid $id): ?Assessment
    {
        $a = $this->em->find(Assessment::class, $id);
        if ($a !== null) {
            $this->reinject($a);
        }
        return $a;
    }

    public function findByCoatingAndSubstance(Uuid $coatingId, Uuid $substanceId): ?Assessment
    {
        /** @var ?Assessment $a */
        $a = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.coatingId = :coatingId')
            ->andWhere('a.substanceId = :substanceId')
            ->setParameter('coatingId', $coatingId->toRfc4122())
            ->setParameter('substanceId', $substanceId->toRfc4122())
            ->getQuery()
            ->getOneOrNullResult();

        if ($a !== null) {
            $this->reinject($a);
        }
        return $a;
    }

    /**
     * @return list<Assessment>
     */
    public function findAllByCoating(Uuid $coatingId): array
    {
        /** @var list<Assessment> $assessments */
        $assessments = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.coatingId = :coatingId')
            ->setParameter('coatingId', $coatingId->toRfc4122())
            ->getQuery()
            ->getResult();

        foreach ($assessments as $a) {
            $this->reinject($a);
        }
        return $assessments;
    }

    /**
     * @return list<Assessment>
     */
    public function findAllBySubstance(Uuid $substanceId): array
    {
        /** @var list<Assessment> $assessments */
        $assessments = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.substanceId = :substanceId')
            ->setParameter('substanceId', $substanceId->toRfc4122())
            ->getQuery()
            ->getResult();

        foreach ($assessments as $a) {
            $this->reinject($a);
        }
        return $assessments;
    }

    public function countAssessmentsWithNoteId(string $noteId): int
    {
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM chemical_resistance_assessment WHERE note_ids @> :id::jsonb',
            ['id' => json_encode([$noteId])],
        );
        return (int) $count;
    }

    public function paginateByCoating(Uuid $coatingId, ?string $search, int $page, int $pageSize): PaginationResult
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->join(Substance::class, 's', 'WITH', 's.id = a.substanceId')
            ->where('a.coatingId = :coatingId')
            ->setParameter('coatingId', $coatingId->toRfc4122())
            ->orderBy('s.canonicalName', 'ASC');

        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(s.canonicalName) LIKE LOWER(:search)',
                    'LOWER(s.canonicalNameKey) LIKE LOWER(:search)',
                    's.cas LIKE :search',
                )
            )
            ->setParameter('search', '%' . $search . '%');
        }

        $qb->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        $paginator = new Paginator($qb->getQuery(), false);

        /** @var list<Assessment> $items */
        $items = iterator_to_array($paginator->getIterator());
        foreach ($items as $a) {
            $this->reinject($a);
        }

        return new PaginationResult($items, $paginator->count());
    }
}
