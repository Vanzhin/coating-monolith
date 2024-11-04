<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;

class Coating extends Aggregate
{
    private readonly string $id;
    private string $title;
    private string $description;
    private int $volumeSolid;
    private CoatingProtectionType $protectionType;
    private Manufacturer $manufacturer;
    private CoatingSpecification $specification;


    public function __construct(
        string                $title,
        string                $description,
        int                   $volumeSolid,
        CoatingProtectionType $protectionType,
        Manufacturer          $manufacturer,
        CoatingSpecification  $specification
    )
    {
        $this->id = UuidService::generate();
        $this->specification = $specification;
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setVolumeSolid($volumeSolid);
        $this->setProtectionType($protectionType);
        $this->manufacturer = $manufacturer;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        AssertService::maxLength($this->title, 100);
        $this->specification->uniqueTitleCoatingSpecification->satisfy($this);
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
        AssertService::maxLength($this->description, 750);
    }

    public function getVolumeSolid(): int
    {
        return $this->volumeSolid;

    }

    public function setVolumeSolid(int $volumeSolid): void
    {
        $this->volumeSolid = $volumeSolid;
        if ($volumeSolid < 1 || $volumeSolid > 100) {
            throw new \Exception("Volume solid must be between 1 and 100");
        }
    }

    public function getProtectionType(): CoatingProtectionType
    {
        return $this->protectionType;
    }

    public function setProtectionType(CoatingProtectionType $protectionType): void
    {
        $this->protectionType = $protectionType;
    }

    public function getManufacturer(): Manufacturer
    {
        return $this->manufacturer;
    }

    public function setManufacturer(Manufacturer $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }

    public function getId(): string
    {
        return $this->id;
    }
}