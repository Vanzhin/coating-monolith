<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Factory;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Service\UuidService;

readonly class CoatingFactory
{
    public function __construct(private CoatingSpecification $coatingSpecification)
    {
    }

    public function create(
        string           $title,
        string           $description,
        int              $volumeSolid,
        float            $massDensity,
        CoatingBase      $base,
        DftRange         $dftRange,
        int              $applicationMinTemp,
        DryingTimeSeries $dryToTouch,
        float            $minRecoatingInterval,
        ?float           $maxRecoatingInterval,
        DryingTimeSeries $fullCure,
        Manufacturer     $manufacturer,
        float            $pack,
        ?string          $thinner,
    ): Coating {
        return new Coating(
            UuidService::generateUuid(),
            $title,
            $description,
            $volumeSolid,
            $massDensity,
            $base,
            $dftRange,
            $applicationMinTemp,
            $dryToTouch,
            $minRecoatingInterval,
            $maxRecoatingInterval,
            $fullCure,
            $pack,
            $thinner,
            $manufacturer,
            $this->coatingSpecification,
        );
    }
}
