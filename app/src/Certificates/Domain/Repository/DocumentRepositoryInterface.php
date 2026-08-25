<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Repository;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface DocumentRepositoryInterface
{
    public function add(Document $document): void;

    public function remove(Document $document): void;

    public function findOneById(string $id): ?Document;

    public function findByFilter(DocumentsFilter $filter): PaginationResult;

    /**
     * Документы, ссылающиеся на конкретного владельца (jsonb-containment по references).
     *
     * @return list<Document>
     */
    public function findByReference(Reference $reference): array;

    /**
     * Кол-во документов по каждому владельцу заданного типа (для read-модели системы).
     *
     * @return array<string, int> ключ — referenceId владельца, значение — число документов
     */
    public function countByReferences(ReferenceType $type, StringCollection $referenceIds): array;
}
