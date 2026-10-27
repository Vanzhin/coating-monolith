<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Factory;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

/**
 * Конструктор запроса: генерирует id (домен id не генерит — это делает фабрика/хендлер) и создаёт
 * агрегат в статусе Received. $now приходит снаружи (из Application), чтобы время было тестируемым.
 */
final class InquiryFactory
{
    public function create(
        InquiryType $type,
        string $ownerId,
        string $rawText,
        StringCollection $attachmentFileIds,
        \DateTimeImmutable $now,
    ): Inquiry {
        return new Inquiry(Uuid::v4(), $type, $ownerId, $rawText, $attachmentFileIds, $now);
    }
}
