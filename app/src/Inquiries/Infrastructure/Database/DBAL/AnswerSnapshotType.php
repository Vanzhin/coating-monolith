<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Database\DBAL;

use App\Inquiries\Domain\Aggregate\Inquiry\AnswerSnapshot;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class AnswerSnapshotType extends AbstractJsonObjectType
{
    public const NAME = 'inquiry_answer_snapshot';

    protected function valueClass(): string
    {
        return AnswerSnapshot::class;
    }

    protected function hydrate(array $raw): object
    {
        return AnswerSnapshot::fromArray($raw);
    }
}
