<?php
declare(strict_types=1);

namespace App\Documents\Domain\Service;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Proposals\Application\DTO\GeneralProposalInfoItem\GeneralProposalInfoItemDTO;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Factory\GeneralProposalInfoFactory;
use App\Proposals\Domain\Factory\GeneralProposalInfoItemFactory;

final readonly class DocumentMaker
{
    //todo
    public function __construct(
        private DocumentRepositoryInterface $documentRepository,
        private GeneralProposalInfoFactory             $generalProposalInfoFactory,
        private GeneralProposalInfoItemFactory         $generalProposalInfoItemFactory,
    )
    {
    }

    public function make(
        string  $number,
        string  $ownerId,
        string  $unit,
        string  $projectTitle,
        float   $projectArea,
        ?string $description,
        ?string $basis,
        ?string $projectStructureDescription,
        ?string $durability,
        ?string $category,
        ?string $treatment,
        ?string $method,
        int     $loss,
        array   $coats,

    ): GeneralProposalInfo
    {
        $generalProposalInfo = $this->generalProposalInfoFactory->create($number,
            $ownerId,
            $unit,
            $projectTitle,
            $projectArea,
            $description,
            $basis,
            $projectStructureDescription,
            $durability,
            $category,
            $treatment,
            $method,
            $loss,
        );

        /** @var GeneralProposalInfoItemDTO $coat */
        foreach ($coats as $coat) {
            $generalProposalInfo->addItem(
                $this->generalProposalInfoItemFactory->create(
                    $coat->coatId,
                    $coat->coatNumber,
                    $coat->coatPrice,
                    $coat->coatDft,
                    $coat->coatColor,
                    $coat->thinnerPrice,
                    $coat->thinnerConsumption,
                    $generalProposalInfo,
                    $coat->loss
                )
            );
        }

        $this->generalProposalInfoRepository->add($generalProposalInfo);

        return $generalProposalInfo;
    }
}