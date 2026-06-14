<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;

class CoatingDTO
{
    public string $id;
    public string $title;
    public string $description;
    public int $volumeSolid;
    public float $massDensity;

    /** ISO 12944-5 аббревиатура. */
    public string $base;

    public DftRangeDTO $dftRange;

    public int $applicationMinTemp;

    /** @var list<DryingTimePointDTO> */
    public array $dryToTouch;

    /** @var list<DryingTimePointDTO> */
    public array $fullCure;

    /** @var list<DryingTimePointDTO> */
    public array $minRecoatingInterval;

    /** @var ?list<DryingTimePointDTO> null = «без верхней границы». */
    public ?array $maxRecoatingInterval = null;

    public float $pack;
    public ?string $thinner;
    public ManufacturerDTO $manufacturer;

    /** @var CoatingTagDTO[] */
    public array $tags;
}
