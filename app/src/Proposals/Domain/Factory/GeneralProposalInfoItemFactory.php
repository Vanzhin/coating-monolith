<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Factory;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoItem;
use App\Proposals\Domain\Aggregate\Proposal\Specification\GeneralProposalInfoItemSpecification;

readonly class GeneralProposalInfoItemFactory
{
    public function __construct(private GeneralProposalInfoItemSpecification $generalProposalInfoItemSpecification)
    {
    }

    public function create(
        string              $coatId,
        int                 $coatNumber,
        float               $coatPrice,
        int                 $coatDft,
        string              $coatColor,
        float               $thinnerPrice,
        int                 $thinnerConsumption,
        GeneralProposalInfo $proposal,
        ?int                $loss,
    ): GeneralProposalInfoItem
    {
        return new GeneralProposalInfoItem(
            $coatId,
            $coatNumber,
            $coatPrice,
            $coatDft,
            $coatColor,
            $thinnerPrice,
            $thinnerConsumption,
            $proposal,
            $this->generalProposalInfoItemSpecification,
            $loss,
        );
    }
}