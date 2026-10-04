<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetProfileCompliance;

use App\Compliance\Application\DTO\ProfileCompliance\ProfileComplianceDTO;

final readonly class GetProfileComplianceQueryResult
{
    public function __construct(public ?ProfileComplianceDTO $compliance)
    {
    }
}
