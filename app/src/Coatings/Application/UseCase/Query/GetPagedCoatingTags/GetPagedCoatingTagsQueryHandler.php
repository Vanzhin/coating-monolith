<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedCoatingTags;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTOTransformer;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

class GetPagedCoatingTagsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly CoatingTagRepositoryInterface $coatingTagRepository,
        private readonly CoatingTagDTOTransformer      $coatingTagDTOTransformer
    )
    {
    }

    public function __invoke(GetPagedCoatingTagsQuery $query): GetPagedCoatingTagsQueryResult
    {
        $paginator = $this->coatingTagRepository->findByFilter($query->filter);
        $coatingTags = $this->coatingTagDTOTransformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total
        );

        return new GetPagedCoatingTagsQueryResult($coatingTags, $pager);
    }
}
