<?php

declare(strict_types=1);

namespace App\Documents\Domain\Repository;

use App\Documents\Domain\Aggregate\Document\Document;
use App\Shared\Domain\Repository\PaginationResult;

interface DocumentRepositoryInterface
{
    public function dbCreate(string $dbTitle, ?array $mappings = null, ?array $settings = null): bool;

    public function dbDelete(string $dbTitle): bool;

    public function bulkInsert(string $data, ?string $dbName = null): bool;

    public function save(Document $document): void;

    public function search(DocumentFilter $filter): PaginationResult;

    public function findCountByCategory(DocumentFilter $filter): array;
}