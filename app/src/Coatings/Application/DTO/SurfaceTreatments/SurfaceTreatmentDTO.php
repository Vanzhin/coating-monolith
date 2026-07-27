<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\SurfaceTreatments;

class SurfaceTreatmentDTO
{
    public string $id;
    public string $description;
    public ?string $code = null;
    public ?string $standardCode = null;
    /** @var list<string> */
    public array $substrateScope = [];
    /** @var list<string> */
    public array $substrateScopeTitles = [];
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
    public ?string $title = null;
}
