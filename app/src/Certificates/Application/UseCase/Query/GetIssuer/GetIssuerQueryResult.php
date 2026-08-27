<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetIssuer;

use App\Certificates\Application\DTO\Issuers\IssuerDTO;

final readonly class GetIssuerQueryResult
{
    public function __construct(public ?IssuerDTO $issuer)
    {
    }
}
