<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Repository;

use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface IssuerRepositoryInterface
{
    public function add(Issuer $issuer): void;

    public function remove(Issuer $issuer): void;

    public function findOneById(string $id): ?Issuer;

    public function findOneByTitle(string $title): ?Issuer;

    /**
     * Массовая выгрузка по id. Возвращает только реально существующие записи.
     *
     * @return list<Issuer>
     */
    public function findByIds(StringCollection $ids): array;

    public function findByFilter(IssuersFilter $filter): PaginationResult;

    /**
     * Префиксный typeahead по названию.
     *
     * @return list<Issuer>
     */
    public function suggest(string $query, int $limit = 10): array;
}
