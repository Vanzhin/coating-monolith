<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetObligationHistory;

use App\Shared\Application\Query\Query;

readonly class GetObligationHistoryQuery extends Query
{
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public string $obligationKey,
    ) {
    }
}
