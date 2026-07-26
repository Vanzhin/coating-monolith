<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;

class CoatingSystemDTOTransformer
{
    public function fromEntity(CoatingSystem $system): CoatingSystemDTO
    {
        $dto = new CoatingSystemDTO();
        $dto->id = $system->getId();
        $dto->title = $system->getTitle();
        $dto->description = $system->getDescription();
        $dto->substrate = $system->getSubstrate()->value;
        $dto->substrateTitle = $system->getSubstrate()->title();
        $dto->surfacePreparationGrade = $system->getSurfacePreparation()->grade;
        $dto->surfacePreparationDescription = $system->getSurfacePreparation()->description;
        $dto->surfacePreparationStandard = $system->getSurfacePreparation()->standard;
        $dto->createdAt = $system->getCreatedAt();
        $dto->updatedAt = $system->getUpdatedAt();
        $dto->totalDft = $system->totalDft();
        $dto->layers = $this->layersFromSystem($system);
        $dto->compliance = [];

        return $dto;
    }

    /** @return list<CoatingSystemLayerDTO> */
    private function layersFromSystem(CoatingSystem $system): array
    {
        $layers = [];
        foreach ($system->getLayers() as $layer) {
            $coating = $layer->getCoating();
            $layerDto = new CoatingSystemLayerDTO();
            $layerDto->id = $layer->getId();
            $layerDto->position = $layer->getPosition();
            $layerDto->dft = $layer->getDft();
            $layerDto->coatingId = $coating->getId();
            $layerDto->coatingTitle = $coating->getTitle();
            $layerDto->coatingBase = $coating->getBase()->value;
            $layerDto->coatingBaseTitle = $coating->getBase()->title();
            $layerDto->isZincRich = $coating->isZincRich();

            $layers[] = $layerDto;
        }

        return $layers;
    }
}
