<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetPagedIssuers;

use App\Certificates\Application\DTO\Issuers\IssuerDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedIssuersQueryResult
{
    /**
     * @param list<IssuerDTO> $issuers
     */
    public function __construct(
        public array $issuers,
        public Pager $pager,
    ) {
    }
}
