<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface CoatingTagRepositoryInterface
{
    public function add(CoatingTag $coatingTag): void;

    public function findByTitle(string $title): PaginationResult;

    public function findByType(string $type): PaginationResult;

    public function findOneById(string $id): ?CoatingTag;

    /**
     * Массовая выгрузка по id. Возвращает только реально существующие теги
     * (несуществующие id молча дропаются — вызывающий сам решает как ошибиться).
     * StringCollection на входе — типизированный список id, а не сырой array
     * куда можно засунуть что угодно.
     *
     * @return list<CoatingTag>
     */
    public function findByIds(StringCollection $ids): array;

    public function findOneByTitleAndType(string $title, ?string $type): ?CoatingTag;

    public function findByFilter(CoatingTagsFilter $filter): PaginationResult;

}