<?php

declare(strict_types=1);

namespace App\Reports\Domain\Repository;

use App\Reports\Domain\Aggregate\Counterparty\Counterparty;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface CounterpartyRepositoryInterface
{
    public function add(Counterparty $counterparty): void;

    public function remove(Counterparty $counterparty): void;

    public function findOneById(string $id): ?Counterparty;

    public function findOneByTitle(string $title): ?Counterparty;

    public function findOneByTin(string $tin): ?Counterparty;

    public function findByFilter(CounterpartiesFilter $filter): PaginationResult;

    /**
     * @return list<Counterparty>
     */
    public function findByIds(StringCollection $ids): array;

    /**
     * @return list<Counterparty>
     */
    public function suggest(string $query, int $limit = 10): array;
}
