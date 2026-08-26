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

    /**
     * Один скачиваемый документ (с файлом) на каждого владельца заданного типа — для кнопки
     * скачивания на карточке. Берём самый свежий по дате выдачи.
     *
     * @return array<string, string> ключ — referenceId владельца, значение — id документа
     */
    public function downloadableByReferences(ReferenceType $type, StringCollection $referenceIds): array;

    /**
     * Уникальные непустые значения «стандарт испытания» — источник опций фасета списка.
     *
     * @return list<string>
     */
    public function distinctTestStandards(): array;

    /**
     * Номера (title) всех существующих документов — для идемпотентности импорта заключений.
     *
     * @return list<string>
     */
    public function existingTitles(): array;
}
