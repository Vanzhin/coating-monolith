<?php
declare(strict_types=1);


namespace App\Coatings\Application\DTO\Coatings;


use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Domain\Aggregate\Coating\Coating;

class CoatingDTOTransformer
{
    public function fromEntity(Coating $entity): object
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

        $dto = new CoatingDTO();
        $dto->id = $entity->getId();
        $dto->title = $entity->getTitle();
        $dto->description = $entity->getDescription();
        $dto->fullCure = $entity->getFullCure();
        $dto->maxRecoatingInterval = $entity->getMaxRecoatingInterval();
        $dto->minRecoatingInterval = $entity->getMinRecoatingInterval();
        $dto->dryToTouch = $entity->getDryToTouch();
        $dto->applicationMinTemp = $entity->getApplicationMinTemp();
        $dto->maxDft = $entity->getMaxDft();
        $dto->minDft = $entity->getMinDft();
        $dto->tdsDft = $entity->getTdsDft();
        $dto->massDensity = $entity->getMassDensity();
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
            $coatingDTOs[] = $this->fromEntity($coating);
        }

        return $coatingDTOs;
    }

}