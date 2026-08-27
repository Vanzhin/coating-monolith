<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\SuggestIssuers;

use App\Certificates\Application\DTO\Issuers\IssuerDTO;

final readonly class SuggestIssuersQueryResult
{
    /**
     * @param list<IssuerDTO> $issuers
     */
    public function __construct(public array $issuers)
    {
    }
}
