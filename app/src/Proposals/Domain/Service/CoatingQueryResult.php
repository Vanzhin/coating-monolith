<?php
declare(strict_types=1);

namespace App\Proposals\Domain\Service;

class CoatingQueryResult
{
    public function __construct(
        public readonly ?CoatingData $coatingData
    ) {
    }
}
