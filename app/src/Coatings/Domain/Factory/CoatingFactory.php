<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Factory;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;


readonly class CoatingFactory
{
    public function __construct(private CoatingSpecification $coatingSpecification)
    {
    }

    public function create(
        string       $title,
        string       $description,
        int          $volumeSolid,
        float        $massDensity,
        int          $tdsDft,
        int          $minDft,
        int          $maxDft,
        int          $applicationMinTemp,
        int          $dryToTouch,
        int          $minRecoatingInterval,
        int          $maxRecoatingInterval,
        int          $fullCure,
        Manufacturer $manufacturer
    ): Coating
    {
        return new Coating($title,
            $description,
            $volumeSolid,
            $massDensity,
            $tdsDft,
            $minDft,
            $maxDft,
            $applicationMinTemp,
            $dryToTouch,
            $minRecoatingInterval,
            $maxRecoatingInterval,
            $fullCure,
            $manufacturer,
            $this->coatingSpecification,
        );
    }

}