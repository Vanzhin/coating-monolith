<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestTags;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Infrastructure\Search\TagFinder;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestTagsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private TagFinder $finder,
        private TagDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestTagsQuery $query): SuggestTagsQueryResult
    {
        $tags = $this->finder->suggest($query->query, $query->type, $query->limit);
        $dtos = $this->transformer->fromEntityList($tags);

        return new SuggestTagsQueryResult($dtos);
    }
}
