<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface TagRepositoryInterface
{
    public function add(Tag $coatingTag): void;

    public function findByTitle(string $title): PaginationResult;

    public function findByType(string $type): PaginationResult;

    public function findOneById(string $id): ?Tag;

    /**
     * Массовая выгрузка по id. Возвращает только реально существующие теги
     * (несуществующие id молча дропаются — вызывающий сам решает как ошибиться).
     * StringCollection на входе — типизированный список id, а не сырой array
     * куда можно засунуть что угодно.
     *
     * @return list<Tag>
     */
    public function findByIds(StringCollection $ids): array;

    public function findOneByTitleAndType(string $title, ?string $type): ?Tag;

    public function findByFilter(TagsFilter $filter): PaginationResult;
}
