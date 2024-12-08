<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\GeneralProposalInfoItem;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoItem;

class GeneralProposalInfoItemDTOTransformer
{
    public function fromEntity(GeneralProposalInfoItem $entity): GeneralProposalInfoItemDTO
    {
        $dto = new GeneralProposalInfoItemDTO();
        $dto->id = $entity->getId();
        $dto->coatId = $entity->getCoatId();
        $dto->coatDft = $entity->getCoatDft();
        $dto->coatPrice = $entity->getCoatPrice();
        $dto->coatNumber = $entity->getCoatNumber();
        $dto->coatColor = $entity->getCoatColor();
        $dto->thinnerPrice = $entity->getThinnerPrice();
        $dto->thinnerConsumption = $entity->getThinnerConsumption();
        $dto->loss = $entity->getLoss();
        $dto->proposalId = $entity->getProposal()->getId();

        return $dto;
    }

    /**
     * @param array<GeneralProposalInfoItem> $layers
     *
     * @return array<GeneralProposalInfoItemDTO>
     */
    public function fromEntityList(array $layers): array
    {
        $generalProposalInfoItemDTOs = [];
        foreach ($layers as $layer) {
            $generalProposalInfoItemDTOs[] = $this->fromEntity($layer);
        }

        return $generalProposalInfoItemDTOs;
    }

}