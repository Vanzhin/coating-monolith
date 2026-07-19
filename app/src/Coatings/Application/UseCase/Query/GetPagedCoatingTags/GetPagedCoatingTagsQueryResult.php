<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedCoatingTags;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Shared\Domain\Repository\Pager;

readonly class GetPagedCoatingTagsQueryResult
{
    /**
     * @param CoatingTagDTO[] $coatingTags
     */
    public function __construct(public array $coatingTags, public Pager $pager)
    {
    }
}
