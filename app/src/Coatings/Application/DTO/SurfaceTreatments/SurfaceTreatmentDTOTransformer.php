<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\SurfaceTreatments;

use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;

class SurfaceTreatmentDTOTransformer
{
    public function fromEntity(SurfaceTreatment $treatment): SurfaceTreatmentDTO
    {
        $dto = new SurfaceTreatmentDTO();
        $dto->id = $treatment->getId();
        $dto->description = $treatment->getDescription();
        $dto->code = $treatment->getCode();
        $dto->standardCode = $treatment->getStandardCode();
        $dto->createdAt = $treatment->getCreatedAt();
        $dto->updatedAt = $treatment->getUpdatedAt();

        $dto->substrateScope = array_map(
            fn ($substrate) => $substrate->value,
            $treatment->getSubstrateScope(),
        );

        $dto->substrateScopeTitles = array_map(
            fn ($substrate) => $substrate->title(),
            $treatment->getSubstrateScope(),
        );

        $dto->title = $this->computeTitle(
            $treatment->getCode(),
            $treatment->getStandardCode(),
            $treatment->getDescription(),
        );

        return $dto;
    }

    private function computeTitle(?string $code, ?string $standardCode, string $description): string
    {
        if (null !== $code && null !== $standardCode) {
            return "{$code} ({$standardCode})";
        }

        if (null !== $code) {
            return $code;
        }

        return $description;
    }
}
