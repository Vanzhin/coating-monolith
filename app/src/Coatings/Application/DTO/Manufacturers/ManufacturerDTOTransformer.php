<?php
declare(strict_types=1);


namespace App\Coatings\Application\DTO\Manufacturers;


use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;

class ManufacturerDTOTransformer
{
    public function fromEntity(Manufacturer $entity): object
    {
        $dto = new ManufacturerDTO();
        $dto->id = $entity->getId();
        $dto->title = $entity->getTitle();
        $dto->description = $entity->getDescription();

        return $dto;
    }

    /**
     * @param array<Manufacturer> $manufacturers
     *
     * @return array<ManufacturerDTO>
     */
    public function fromEntityList(array $manufacturers): array
    {
        $manufacturerDTOs = [];
        foreach ($manufacturers as $manufacturer) {
            $manufacturerDTOs[] = $this->fromEntity($manufacturer);
        }

        return $manufacturerDTOs;
    }

}