<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\GeneralProposalInfo;


use App\Proposals\Application\DTO\GeneralProposalInfoItem\GeneralProposalInfoItemDTOTransformer;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;

readonly class GeneralProposalInfoDTOTransformer
{
    public function __construct(private GeneralProposalInfoItemDTOTransformer $itemDTOTransformer)
    {
    }

    public function fromEntity(GeneralProposalInfo $entity): object
    {
        $itemDTOs = $this->itemDTOTransformer->fromEntityList($entity->getCoats()->toArray());
        $dto = new GeneralProposalInfoDTo();
        $dto->id = $entity->getId();
        $dto->number = $entity->getNumber();
        $dto->description = $entity->getDescription();
        $dto->basis = $entity->getBasis();
        $dto->createdAt = $entity->getCreatedAt()->format(DATE_ATOM);
        $dto->updatedAt = $entity->getUpdatedAt()?->format(DATE_ATOM);
        $dto->ownerId = $entity->getOwnerId();
        $dto->unit = $entity->getUnit()->value;
        $dto->projectTitle = $entity->getProjectTitle();
        $dto->projectArea = $entity->getProjectArea();
        $dto->projectStructureDescription = $entity->getProjectStructureDescription();
        $dto->loss = $entity->getLoss();
        $dto->durability = $entity->getDurability()->value;
        $dto->category = $entity->getCategory()->value;
        $dto->treatment = $entity->getTreatment()->value;
        $dto->method = $entity->getMethod()->value;
        $dto->coats = $itemDTOs;

        return $dto;
    }

    /**
     * @param array<GeneralProposalInfo> $generalProposalInfo
     *
     * @return array<GeneralProposalInfoDTO>
     */
    public function fromEntityList(array $generalProposalInfo): array
    {
        $DTOs = [];
        foreach ($generalProposalInfo as $item) {
            $DTOs[] = $this->fromEntity($item);
        }

        return $DTOs;
    }

}