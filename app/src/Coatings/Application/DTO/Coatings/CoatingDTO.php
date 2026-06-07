<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;

class CoatingDTO
{
    public string $id;
    public string $title;
    public string $description;
    public int $volumeSolid;
    public float $massDensity;

    /** ISO 12944-5 аббревиатура. */
    public string $base;

    public function baseEnum(): ?CoatingBase
    {
        return CoatingBase::tryFrom($this->base);
    }

    /** @var array{min: int, max: int, tds_dft: int, type: string} */
    public array $dftRange;

    public int $applicationMinTemp;

    /** @var list<array{temperature_at: int, time_in_minutes: float, is_calculated: bool}> */
    public array $dryToTouch;

    public float $minRecoatingInterval;
    public ?float $maxRecoatingInterval;

    /** @var list<array{temperature_at: int, time_in_minutes: float, is_calculated: bool}> */
    public array $fullCure;

    public float $pack;
    public ?string $thinner;
    public ManufacturerDTO $manufacturer;

    /** @var CoatingTagDTO[] */
    public array $tags;
}
