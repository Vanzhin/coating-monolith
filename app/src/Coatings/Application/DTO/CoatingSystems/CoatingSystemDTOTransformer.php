<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;

class CoatingSystemDTOTransformer
{
    public function __construct(
        private readonly ComplianceEvaluator $evaluator,
        private readonly TagDTOTransformer $tagTransformer = new TagDTOTransformer(),
    ) {
    }

    public function fromEntity(CoatingSystem $system): CoatingSystemDTO
    {
        $treatment = $system->getSurfaceTreatment();

        $dto = new CoatingSystemDTO();
        $dto->id = $system->getId();
        $dto->title = $system->getTitle();
        $dto->description = $system->getDescription();
        $dto->substrate = $system->getSubstrate()->value;
        $dto->substrateTitle = $system->getSubstrate()->title();
        $dto->surfaceTreatmentId = $treatment->getId();
        $dto->surfaceTreatmentDescription = $treatment->getDescription();
        $dto->surfaceTreatmentCode = $treatment->getCode();
        $dto->surfaceTreatmentStandardCode = $treatment->getStandardCode();
        $dto->surfaceTreatmentTitle = $treatment->getCode() ?? $treatment->getDescription();
        $dto->createdAt = $system->getCreatedAt();
        $dto->updatedAt = $system->getUpdatedAt();
        $dto->totalDft = $system->totalDft();
        $dto->minApplicationTimeAt20Minutes = $system->minApplicationTimeAt20Minutes();
        $dto->maxLayerApplicationMinTemp = $system->maxLayerApplicationMinTemp();
        $dto->layers = $this->layersFromSystem($system);
        $dto->compliance = $system->complianceMatches($this->evaluator)->jsonSerialize();
        $dto->tags = array_values($this->tagTransformer->fromEntityList($system->getTags()->toArray()));

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
            $layerDto->manufacturerTitle = $coating->getManufacturer()->getTitle();
            $layerDto->dftMin = (int) $coating->getDftRange()->range->getMin();
            $layerDto->dftMax = (int) $coating->getDftRange()->range->getMax();

            $layers[] = $layerDto;
        }

        return $layers;
    }
}
