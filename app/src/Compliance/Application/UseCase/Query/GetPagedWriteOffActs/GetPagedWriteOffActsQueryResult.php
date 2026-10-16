<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetPagedWriteOffActs;

use App\Compliance\Application\DTO\WriteOffAct\WriteOffActListItemDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedWriteOffActsQueryResult
{
    /**
     * @param list<WriteOffActListItemDTO> $items
     */
    public function __construct(public array $items, public Pager $pager)
    {
    }
}
