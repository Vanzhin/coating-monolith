<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestTags;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;

final readonly class SuggestTagsQueryResult
{
    /** @param list<CoatingTagDTO> $tags */
    public function __construct(public array $tags)
    {
    }
}
