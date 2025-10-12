<?php
declare(strict_types=1);

namespace App\Documents\Domain\Service;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Proposals\Domain\Service\GeneralProposalInfoItemDataInterface;
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

        /** @var GeneralProposalInfoItemDataInterface $coat */
        foreach ($coats as $coat) {
            $generalProposalInfo->addItem(
                $this->generalProposalInfoItemFactory->create(
                    $coat->getCoatId(),
                    $coat->getCoatNumber(),
                    $coat->getCoatPrice(),
                    $coat->getCoatDft(),
                    $coat->getCoatColor(),
                    $coat->getThinnerPrice(),
                    $coat->getThinnerConsumption(),
                    $generalProposalInfo,
                    $coat->getLoss()
                )
            );
        }

        $this->generalProposalInfoRepository->add($generalProposalInfo);

        return $generalProposalInfo;
    }
}