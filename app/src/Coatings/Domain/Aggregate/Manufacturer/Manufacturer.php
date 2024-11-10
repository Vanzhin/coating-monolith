<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Manufacturer;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class Manufacturer extends Aggregate
{
    private readonly string $id;
    private string $title;
    private ?string $description;
    /**
     * @var Collection<Coating>
     */
    private Collection $coatings;

    private ManufacturerSpecification $manufacturerSpecification;

    public function __construct(
        string                    $title,
        ManufacturerSpecification $manufacturerSpecification,
        string                    $description = null,
    )
    {
        $this->manufacturerSpecification = $manufacturerSpecification;
        $this->id = UuidService::generate();
        $this->coatings = new ArrayCollection();
        $this->setTitle($title);
        $this->setDescription($description);
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        AssertService::maxLength($this->title, 100);
        $this->manufacturerSpecification->uniqueTitleManufacturerSpecification->satisfy($this);
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        AssertService::maxLength($this->description, 750);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCoatings(): Collection
    {
        return $this->coatings;
    }

    public function getId(): string
    {
        return $this->id;
    }
}