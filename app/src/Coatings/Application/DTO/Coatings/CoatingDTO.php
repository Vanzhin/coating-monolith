<?php
declare(strict_types=1);


namespace App\Coatings\Application\DTO\Coatings;

use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;

class CoatingDTO
{
    public string $id;
    public string $title;
    public string $description;
    public int $volumeSolid;
    public float $massDensity;
    public int $tdsDft;
    public int $minDft;
    public int $maxDft;
    public int $applicationMinTemp;
    public int $dryToTouch;
    public int $minRecoatingInterval;
    public int $maxRecoatingInterval;
    public int $fullCure;
    public ManufacturerDTO $manufacturer;
}