<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
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

    public function countByCompliance(
        ComplianceStandard $standard,
        string $category,
        string $durability,
        ?Substrate $substrate,
    ): int;

    /**
     * @return list<array{standard: string, category: string, durability: string}>
     */
    public function findComplianceRows(Uuid $systemId): array;

    public function countUsingSurfaceTreatment(string $treatmentId): int;

    /** @return list<CoatingSystem> */
    public function findByLayerCoatingId(string $coatingId): array;

    /** @return list<CoatingSystem> */
    public function findAll(): array;

    /**
     * Массовая выгрузка систем по id, порядок соответствует $ids.
     * Отсутствующие id молча пропускаются.
     *
     * @param list<string> $ids
     *
     * @return list<CoatingSystem>
     */
    public function findByIds(array $ids): array;
}
