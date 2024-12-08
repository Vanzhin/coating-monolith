<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Query\GetGeneralProposalInfo;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTOTransformer;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetGeneralProposalInfoQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository,
        private GeneralProposalInfoDTOTransformer      $generalProposalInfoDTOTransformer
    )
    {
    }

    public function __invoke(GetGeneralProposalInfoQuery $query): GetGeneralProposalInfoQueryResult
    {
        $proposal = $this->generalProposalInfoRepository->findOneById($query->proposalId);
        if (null === $proposal) {
            return new GetGeneralProposalInfoQueryResult(null);
        }

        return new GetGeneralProposalInfoQueryResult($this->generalProposalInfoDTOTransformer->fromEntity($proposal));
    }
}
