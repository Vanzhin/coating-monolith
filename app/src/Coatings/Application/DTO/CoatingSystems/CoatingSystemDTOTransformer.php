<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Compliance\Compliance;
use App\Coatings\Domain\Compliance\ComplianceFacetsRegistry;

class CoatingSystemDTOTransformer
{
    public function __construct(
        private readonly ComplianceFacetsRegistry $facetsRegistry,
        private readonly TagDTOTransformer $tagTransformer = new TagDTOTransformer(),
    ) {
    }

    /**
     * @param list<Compliance> $compliance          соответствия системы из read-model (снапшота)
     * @param int              $documentCount       число привязанных документов (Certificates)
     * @param ?string          $documentDownloadUrl URL скачивания документа системы (если есть файл)
     */
    public function fromEntity(
        CoatingSystem $system,
        array $compliance = [],
        int $documentCount = 0,
        ?string $documentDownloadUrl = null,
    ): CoatingSystemDTO {
        $treatment = $system->getSurfaceTreatment();

        $dto = new CoatingSystemDTO();
        $dto->id = $system->getId();
        $dto->title = $system->getTitle();
        $dto->description = $system->getDescription();
        $dto->substrate = $system->getSubstrate()->value;
        $dto->substrateTitle = $system->getSubstrate()->title();
        $dto->environment = $system->getEnvironment()->value;
        $dto->environmentTitle = $system->getEnvironment()->title();
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
        $dto->compliance = array_map(
            fn (Compliance $c) => $this->complianceDto($c),
            $compliance,
        );
        $dto->tags = array_values($this->tagTransformer->fromEntityList($system->getTags()->toArray()));
        $dto->documentCount = $documentCount;
        $dto->documentDownloadUrl = $documentDownloadUrl;

        return $dto;
    }

    private function complianceDto(Compliance $c): ComplianceMatchDTO
    {
        $facets = $this->facetsRegistry->facetsFor($c->standard);
        $label = null !== $facets ? $facets->badgeLabel($c->primary, $c->secondary) : $c->primary;

        return new ComplianceMatchDTO(
            $c->standard->value,
            $c->standard->title(),
            $label,
            $c->primary,
            $c->secondary ?? '',
        );
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

            $color = $layer->getColor();
            if (null !== $color) {
                $layerDto->colorId = $color->getId();
                $layerDto->colorName = $color->getName();
                $layerDto->colorRal = $color->getRal();
                $layerDto->colorHex = $color->getHex();
                $layerDto->colorLabel = $color->label();
            }

            $layers[] = $layerDto;
        }

        return $layers;
    }
}
