<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetIssuePage;

/** Открытый черновик акта списания требования — точка входа «Оформить» на `/issue` (без резолва меток строк). */
final class IssueWriteOffActSummaryDTO
{
    public function __construct(
        public string $id,
        public bool $signed,
        public int $itemCount,
        public ?string $actNumber = null,
    ) {
    }
}
