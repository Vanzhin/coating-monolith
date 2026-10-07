<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Requirement;

use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\RequirementItemInterface;

class RequirementDTOTransformer
{
    /**
     * @param array<string, string> $positionTitles id должности → название (резолв через Personnel-шину)
     */
    public function fromEntity(Requirement $requirement, array $positionTitles): RequirementDTO
    {
        $dto = new RequirementDTO();
        $dto->id = $requirement->getId();
        $dto->name = $requirement->getName();
        $dto->type = $requirement->getType()->value;
        $dto->typeLabel = $requirement->getType()->title();
        $dto->hasTemplate = null !== $requirement->getTemplateFileId();
        $dto->positions = array_map(
            static fn (string $id): PositionRefDTO => new PositionRefDTO($id, $positionTitles[$id] ?? ''),
            $requirement->getPositionIds()->getList(),
        );
        $dto->items = array_map($this->itemDto(...), $requirement->getItems());

        return $dto;
    }

    private function itemDto(RequirementItemInterface $item): RequirementItemDTO
    {
        $dto = new RequirementItemDTO();
        $dto->label = $item->label();
        $dto->cadenceKind = $item->cadence()->kind->value;
        $dto->cadenceNumber = $item->cadence()->number;
        $dto->cadenceUnit = $item->cadence()->unit?->value;
        $dto->cadenceLabel = $item->cadence()->label();
        $dto->basis = $item->basis();

        if ($item instanceof MaterialItem) {
            $dto->amount = $item->quantity()->amount;
            $dto->unit = $item->quantity()->unit->value;
            $dto->quantityLabel = $item->quantity()->label();
        }

        return $dto;
    }
}
