<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Query\GetPagedGeneralProposalInfo;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTOTransformer;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

readonly class GetPagedGeneralProposalInfoQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository,
        private GeneralProposalInfoDTOTransformer      $generalProposalInfoDTOTransformer
    )
    {
    }

    public function __invoke(GetPagedGeneralProposalInfoQuery $query): GetPagedGeneralProposalInfoQueryResult
    {
        $paginator = $this->generalProposalInfoRepository->findByFilter($query->filter);
        $proposals = $this->generalProposalInfoDTOTransformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total
        );

        return new GetPagedGeneralProposalInfoQueryResult($proposals, $pager);
    }
}
