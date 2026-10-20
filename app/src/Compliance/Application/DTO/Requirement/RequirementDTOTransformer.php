<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Requirement;

use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\ValueObject\Item\QuantifiedItem;
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
        $dto->journalKind = $requirement->getJournalKind()?->value;
        $dto->positions = array_map(
            static fn (string $id): PositionRefDTO => new PositionRefDTO($id, $positionTitles[$id] ?? ''),
            $requirement->getPositionIds()->getList(),
        );
        // ВСЕ позиции (не только activeItems()) — редактор требования должен показать removed-надгробия
        // (greyed+lock, «Восстановить»); потребители, которым removed мешает (список требований), фильтруют
        // сами — см. ListRequirementsQueryHandler.
        $dto->items = array_map($this->itemDto(...), $requirement->getItems());

        return $dto;
    }

    private function itemDto(RequirementItemInterface $item): RequirementItemDTO
    {
        $dto = new RequirementItemDTO();
        $dto->id = $item->id();
        $dto->label = $item->label();
        $dto->type = $item->type()->value;
        $dto->cadenceKind = $item->cadence()->kind->value;
        $dto->cadenceNumber = $item->cadence()->number;
        $dto->cadenceUnit = $item->cadence()->unit?->value;
        $dto->cadenceLabel = $item->cadence()->label();
        $dto->basis = $item->basis();
        $dto->removed = $item->isRemoved();

        if ($item instanceof QuantifiedItem) {
            $dto->amount = $item->quantity()->amount;
            $dto->unit = $item->quantity()->unit->value;
            $dto->quantityLabel = $item->quantity()->label();
        }

        return $dto;
    }
}
