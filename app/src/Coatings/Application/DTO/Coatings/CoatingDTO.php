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
    public int $tdsDft;
    public int $minDft;
    public int $maxDft;
    public int $applicationMinTemp;
    public float $dryToTouch;
    public float $minRecoatingInterval;
    public float $maxRecoatingInterval;
    public float $fullCure;
    public float $pack;
    public ?string $thinner;
    public ManufacturerDTO $manufacturer;
    /**
     * @var CoatingTagDTO[]
     */
    public array $tags;
}