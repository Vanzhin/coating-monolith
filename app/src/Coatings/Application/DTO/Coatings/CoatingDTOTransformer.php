<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Domain\Aggregate\Coating\Coating;

class CoatingDTOTransformer
{
    public function fromEntity(Coating $entity): CoatingDTO
    {
        $manufacturerDto = new ManufacturerDTO();
        $manufacturerDto->id = $entity->getManufacturer()->getId();
        $manufacturerDto->title = $entity->getManufacturer()->getTitle();
        $manufacturerDto->description = $entity->getManufacturer()->getDescription();

        $coatingTagDtos = [];
        foreach ($entity->getTags() as $tag) {
            $coatingTagDto = new CoatingTagDTO();
            $coatingTagDto->id = $tag->getId();
            $coatingTagDto->title = $tag->getTitle();
            $coatingTagDto->type = $tag->getType();

            $coatingTagDtos[] = $coatingTagDto;
        }

        $dftRange = $entity->getDftRange();

        $dto = new CoatingDTO();
        $dto->id = $entity->getId();
        $dto->title = $entity->getTitle();
        $dto->description = $entity->getDescription();
        $dto->fullCure = $entity->getFullCure()->jsonSerialize();
        $dto->maxRecoatingInterval = $entity->getMaxRecoatingInterval();
        $dto->minRecoatingInterval = $entity->getMinRecoatingInterval();
        $dto->dryToTouch = $entity->getDryToTouch()->jsonSerialize();
        $dto->applicationMinTemp = $entity->getApplicationMinTemp();
        $dto->dftRange = [
            'min' => (int) $dftRange->range->getMin(),
            'max' => (int) $dftRange->range->getMax(),
            'tds_dft' => $dftRange->tdsDft,
            'type' => $dftRange->type->value,
        ];
        $dto->massDensity = $entity->getMassDensity();
        $dto->base = $entity->getBase()->value;
        $dto->volumeSolid = $entity->getVolumeSolid();
        $dto->pack = $entity->getPack();
        $dto->manufacturer = $manufacturerDto;
        $dto->thinner = $entity->getThinner();
        $dto->tags = $coatingTagDtos;

        return $dto;
    }

    /**
     * @param array<Coating> $coatings
     *
     * @return array<CoatingDTO>
     */
    public function fromEntityList(array $coatings): array
    {
        $coatingDTOs = [];
        foreach ($coatings as $coating) {
            $coatingDTOs[$coating->getId()] = $this->fromEntity($coating);
        }

        return $coatingDTOs;
    }
}
