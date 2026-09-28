<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Repository;

use App\Personnel\Domain\Aggregate\Position\Position;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

interface PositionRepositoryInterface
{
    public function add(Position $position): void;

    public function remove(Position $position): void;

    public function findOneById(string $id): ?Position;

    public function findOneByTitle(string $title): ?Position;

    /**
     * @return list<Position>
     */
    public function findByIds(StringCollection $ids): array;

    /**
     * @return list<Position>
     */
    public function suggest(string $query, int $limit = 10): array;
}
