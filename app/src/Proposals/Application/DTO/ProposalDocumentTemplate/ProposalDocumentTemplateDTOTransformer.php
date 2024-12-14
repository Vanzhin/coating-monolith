<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\ProposalDocumentTemplate;


use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTO;
use App\Proposals\Application\DTO\GeneralProposalInfoItem\GeneralProposalInfoItemDTOTransformer;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;

readonly class ProposalDocumentTemplateDTOTransformer
{
    //todo нужен ли?
    public function __construct(private GeneralProposalInfoItemDTOTransformer $itemDTOTransformer)
    {
    }

    public function fromEntity(GeneralProposalInfo $entity): object
    {
        $itemDTOs = $this->itemDTOTransformer->fromEntityList($entity->getCoats()->toArray());
        $dto = new GeneralProposalInfoDTO();
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

    public function fromArray(array $inputData): object
    {
        $dto = new GeneralProposalInfoDTO();
        $dto->id = $inputData['id'] ?? null;
        $dto->number = $inputData['number'];
        $dto->ownerId = $inputData['ownerId'];
        $dto->description = $inputData['description'] ?? null;
        $dto->basis = $inputData['basis'] ?? null;
        $dto->projectArea = (float)$inputData['projectArea'];
        $dto->loss = (int)$inputData['loss'];
        $dto->projectTitle = $inputData['projectTitle'] ?? null;
        $dto->projectStructureDescription = $inputData['projectStructureDescription'] ?? null;
        $dto->durability = $inputData['durability'];
        $dto->treatment = $inputData['treatment'];
        $dto->category = $inputData['category'];
        $dto->method = $inputData['method'];
        $dto->unit = $inputData['unit'];
        $coats = [];
        foreach ($inputData['coats'] ?? [] as $coat) {
            $itemDto = new GeneralProposalInfoItemDTO();
            $itemDto->id = $coat['id'] ?? null;
            $itemDto->coatId = $coat['coatId'];
            $itemDto->loss = empty($coat['loss']) ? null : (int)$coat['loss'];
            $itemDto->coatPrice = (float)$coat['coatPrice'];
            $itemDto->coatNumber = (int)$coat['coatNumber'];
            $itemDto->coatDft = (int)$coat['coatDft'];
            $itemDto->coatColor = $coat['coatColor'];
            $itemDto->thinnerPrice = (int)$coat['thinnerPrice'];
            $itemDto->thinnerConsumption = (int)$coat['thinnerConsumption'];
            $coats[] = $itemDto;
        }
        $dto->coats = $coats;

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