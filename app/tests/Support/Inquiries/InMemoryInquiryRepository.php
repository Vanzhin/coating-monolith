<?php

declare(strict_types=1);

namespace App\Tests\Support\Inquiries;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;

/**
 * In-memory фейк репозитория запросов для юнит-тестов (без БД). Хранит один запрос, считает
 * сохранения — чтобы проверять, что диспетчер сохранил результат (или не трогал при пропуске).
 */
final class InMemoryInquiryRepository implements InquiryRepositoryInterface
{
    public int $saveCount = 0;

    public function __construct(private ?Inquiry $inquiry = null)
    {
    }

    public function add(Inquiry $inquiry): void
    {
        $this->inquiry = $inquiry;
        ++$this->saveCount;
    }

    public function remove(Inquiry $inquiry): void
    {
        $this->inquiry = null;
    }

    public function findOneById(string $id): ?Inquiry
    {
        return $this->inquiry;
    }

    public function findRecognizedByContentHash(string $contentHash, InquiryType $type): ?Inquiry
    {
        return null; // юнит-фейк: кэш-дубликаты проверяются функционально
    }

    public function findByFilter(InquiriesFilter $filter): PaginationResult
    {
        $items = null !== $this->inquiry ? [$this->inquiry] : [];

        return new PaginationResult($items, \count($items));
    }
}
