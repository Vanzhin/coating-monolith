<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Position;

use App\Personnel\Domain\Aggregate\Position\Position;

class PositionDTOTransformer
{
    public function fromEntity(Position $position): PositionDTO
    {
        $dto = new PositionDTO();
        $dto->id = $position->getId();
        $dto->title = $position->getTitle();

        return $dto;
    }

    /**
     * @param iterable<Position> $positions
     *
     * @return list<PositionDTO>
     */
    public function fromEntityList(iterable $positions): array
    {
        $dtos = [];
        foreach ($positions as $position) {
            $dtos[] = $this->fromEntity($position);
        }

        return $dtos;
    }
}
