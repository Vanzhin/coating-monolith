<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedTags;

use App\Coatings\Application\DTO\Tags\TagDTO;
use App\Shared\Domain\Repository\Pager;

readonly class GetPagedTagsQueryResult
{
    /**
     * @param TagDTO[] $coatingTags
     */
    public function __construct(public array $coatingTags, public Pager $pager)
    {
    }
}
