<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;

class CoatingSystemDTOTransformer
{
    public function __construct(
        private readonly TagDTOTransformer $tagTransformer = new TagDTOTransformer(),
    ) {
    }

    /**
     * @param list<array{standard: string, category: string, durability: string}> $complianceRows
     */
    public function fromEntity(CoatingSystem $system, array $complianceRows = []): CoatingSystemDTO
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
        $dto->layers = $this->layersFromSystem($system);
        $dto->compliance = $this->normalizeComplianceRows($complianceRows);
        $dto->tags = array_values($this->tagTransformer->fromEntityList($system->getTags()->toArray()));

        return $dto;
    }

    /**
     * @param list<array{standard: string, category: string, durability: string}> $rows
     *
     * @return list<array{standard: string, standardTitle: string, category: string, durability: string}>
     */
    private function normalizeComplianceRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $standard = ComplianceStandard::from($row['standard']);
            $result[] = [
                'standard' => $row['standard'],
                'standardTitle' => $standard->title(),
                'category' => $row['category'],
                'durability' => $row['durability'],
            ];
        }

        return $result;
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
