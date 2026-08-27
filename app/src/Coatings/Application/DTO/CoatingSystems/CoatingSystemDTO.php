<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Application\DTO\Tags\TagDTO;

class CoatingSystemDTO
{
    public string $id;
    public string $title;
    public string $description;
    public string $substrate;
    public string $substrateTitle;
    public string $environment;
    public string $environmentTitle;
    public string $surfaceTreatmentId;
    public string $surfaceTreatmentDescription;
    public ?string $surfaceTreatmentCode = null;
    public ?string $surfaceTreatmentStandardCode = null;
    public string $surfaceTreatmentTitle;
    /** @var list<CoatingSystemLayerDTO> */
    public array $layers = [];
    public int $totalDft;
    public ?int $minApplicationTimeAt20Minutes = null;
    public ?int $maxLayerApplicationMinTemp = null;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
    /** @var list<ComplianceMatchDTO> */
    public array $compliance = [];
    /** @var list<TagDTO> */
    public array $tags = [];
    /** Число привязанных документов (сертификатов/заключений) — из контекста Certificates. */
    public int $documentCount = 0;
    public ?string $documentDownloadUrl = null;
}
