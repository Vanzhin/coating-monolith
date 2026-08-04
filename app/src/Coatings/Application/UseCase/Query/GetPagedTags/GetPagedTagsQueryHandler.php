<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedTags;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

class GetPagedTagsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly TagRepositoryInterface $coatingTagRepository,
        private readonly TagDTOTransformer $coatingTagDTOTransformer
    ) {
    }

    public function __invoke(GetPagedTagsQuery $query): GetPagedTagsQueryResult
    {
        $paginator = $this->coatingTagRepository->findByFilter($query->filter);
        $coatingTags = $this->coatingTagDTOTransformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total
        );

        return new GetPagedTagsQueryResult($coatingTags, $pager);
    }
}
