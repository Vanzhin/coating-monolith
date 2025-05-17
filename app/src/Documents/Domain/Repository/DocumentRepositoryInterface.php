<?php
declare(strict_types=1);


namespace App\Documents\Domain\Repository;

interface DocumentRepositoryInterface
{
    public function dbCreate(string $dbTitle, ?array $mappings = null, ?array $settings = null): bool;

    public function dbDelete(string $dbTitle): bool;

    public function bulkInsert(string $itemsData): array;

//    public function search(BookFilter $filter, ?string $dbTitle = null): PaginationResult;

}