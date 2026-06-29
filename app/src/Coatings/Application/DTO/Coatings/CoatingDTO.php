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

    public function getBaseEnum(): ?CoatingBase
    {
        return CoatingBase::tryFrom($this->base);
    }

    public DftRangeDTO $dftRange;

    public int $applicationMinTemp;

    /** Верхняя граница рабочего температурного диапазона. По умолчанию 50 °C. */
    public int $dryingMaxTemp = 50;

    /** @var list<DryingTimePointDTO> */
    public array $dryToTouch;

    /** @var list<DryingTimePointDTO> */
    public array $fullCure;

    public RecoatingIntervalTreeDTO $minRecoatingInterval;

    public ?RecoatingIntervalTreeDTO $maxRecoatingInterval = null;

    public float $pack;
    public ?string $thinner;
    public ManufacturerDTO $manufacturer;

    /** @var CoatingTagDTO[] */
    public array $tags;
}
