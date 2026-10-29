<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Repository;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Shared\Domain\Repository\PaginationResult;

/**
 * Репозиторий запросов: запись, резолв по id (резолв ≠ поиск — отдельный метод) и очередь инженера
 * через единый findByFilter (новые оси поиска — поле в InquiriesFilter, не новый метод).
 */
interface InquiryRepositoryInterface
{
    public function add(Inquiry $inquiry): void;

    public function remove(Inquiry $inquiry): void;

    public function findOneById(string $id): ?Inquiry;

    /** Резолв для кэша точного дубликата: распознанный/одобренный близнец с тем же отпечатком входа (не поиск). */
    public function findRecognizedByContentHash(string $contentHash, InquiryType $type): ?Inquiry;

    public function findByFilter(InquiriesFilter $filter): PaginationResult;
}
