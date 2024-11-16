<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Shared\Domain\Repository\PaginationResult;

interface CoatingTagRepositoryInterface
{
    public function add(CoatingTag $coatingTag): void;

    public function findByTitle(string $title): PaginationResult;

    public function findByType(string $type): PaginationResult;

    public function findOneById(string $id): ?CoatingTag;

    public function findOneByTitleAndType(string $title, ?string $type): ?CoatingTag;
}