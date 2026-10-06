<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Shared\Domain\Repository\PaginationResult;

/**
 * Чтение актов выдачи (RequirementDocument) списком. Единая точка поиска/листинга — {@see findByFilter}
 * (поиск по новому параметру = новое ПОЛЕ фильтра, не новый метод). items — доменные RequirementDocument;
 * ФИО/имя требования обогащает Application-хендлер (кросс-контекст).
 */
interface ComplianceActRepositoryInterface
{
    public function findByFilter(ComplianceActsFilter $filter): PaginationResult;

    /**
     * Профиль и требование документа по его id — для резолва превью акта (вход — только documentId).
     *
     * @return array{profileId: string, requirementId: string}|null
     */
    public function profileAndRequirementOf(string $documentId): ?array;

    /**
     * Число позиций (записей выдачи) по каждому документу — для «N позиций» в списке.
     *
     * @param list<string> $documentIds
     *
     * @return array<string, int> documentId → количество
     */
    public function countPositionsByDocuments(array $documentIds): array;
}
