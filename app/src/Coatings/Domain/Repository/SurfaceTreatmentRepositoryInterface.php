<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use Symfony\Component\Uid\Uuid;

interface SurfaceTreatmentRepositoryInterface
{
    public function save(SurfaceTreatment $t): void;

    public function remove(SurfaceTreatment $t): void;

    public function findById(Uuid $id): ?SurfaceTreatment;

    /** @return list<SurfaceTreatment> */
    public function list(SurfaceTreatmentsFilter $filter, int $limit, int $offset): array;

    public function count(SurfaceTreatmentsFilter $filter): int;
}
