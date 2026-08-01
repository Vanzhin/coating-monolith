<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestTags;

use App\Coatings\Application\DTO\Tags\TagDTO;

final readonly class SuggestTagsQueryResult
{
    /** @param list<TagDTO> $tags */
    public function __construct(public array $tags)
    {
    }
}
