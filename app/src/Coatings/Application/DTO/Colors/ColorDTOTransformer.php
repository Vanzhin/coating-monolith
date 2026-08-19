<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Colors;

use App\Coatings\Domain\Aggregate\Color\Color;

final readonly class ColorDTOTransformer
{
    public function fromEntity(Color $color): ColorDTO
    {
        $dto = new ColorDTO();
        $dto->id = $color->getId();
        $dto->name = $color->getName();
        $dto->ral = $color->getRal();
        $dto->hex = $color->getHex();
        $dto->label = $color->label();

        return $dto;
    }

    /**
     * @param iterable<Color> $colors
     *
     * @return list<ColorDTO>
     */
    public function fromEntityList(iterable $colors): array
    {
        $dtos = [];
        foreach ($colors as $color) {
            $dtos[] = $this->fromEntity($color);
        }

        return $dtos;
    }
}
