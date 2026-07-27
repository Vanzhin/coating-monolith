<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

class CoatingSystemDTO
{
    public string $id;
    public string $title;
    public string $description;
    public string $substrate;
    public string $substrateTitle;
    public string $surfaceTreatmentId;
    public string $surfaceTreatmentDescription;
    public ?string $surfaceTreatmentCode = null;
    public ?string $surfaceTreatmentStandardCode = null;
    public string $surfaceTreatmentTitle;
    /** @var list<CoatingSystemLayerDTO> */
    public array $layers = [];
    public int $totalDft;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
    /** @var list<array{standard: string, standardTitle: string, category: string, durability: string}> */
    public array $compliance = [];
}
