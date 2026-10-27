<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Repository;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;

/**
 * Репозиторий запросов. Пока только запись и резолв по id (резолв ≠ поиск — отдельный метод по
 * конвенции). Листинг очереди инженера (findByFilter) добавится отдельной задачей (веб).
 */
interface InquiryRepositoryInterface
{
    public function add(Inquiry $inquiry): void;

    public function findOneById(string $id): ?Inquiry;
}
