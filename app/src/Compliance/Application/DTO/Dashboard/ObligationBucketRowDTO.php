<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Строка обязанности в детали человека: Позиция · Норма · Посл. выдача · След. срок · Статус (бакет). */
final class ObligationBucketRowDTO
{
    public string $label;
    public string $spec;
    public ?string $lastFulfilledAt = null;
    public ?string $nextDueAt = null;
    public string $bucket;
    public string $bucketLabel;
}
