<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Compliance\Application\DTO\Dashboard\PersonRowDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedComplianceQueryResult
{
    /**
     * @param list<PersonRowDTO> $people
     */
    public function __construct(public array $people, public Pager $pager)
    {
    }
}
