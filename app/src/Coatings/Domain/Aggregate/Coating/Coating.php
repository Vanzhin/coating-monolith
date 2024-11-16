<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class Coating extends Aggregate
{
    private readonly string $id;
    private string $title;
    private string $description;
    private int $volumeSolid;
    private float $massDensity;
    private int $tdsDft;
    private int $minDft;
    private int $maxDft;
    private int $applicationMinTemp;
    private int $dryToTouch;
    private int $minRecoatingInterval;
    private int $maxRecoatingInterval;
    private int $fullCure;
    private Manufacturer $manufacturer;
    private CoatingSpecification $specification;

    /**
     * @var Collection<CoatingTag>
     */
    private Collection $tags;


    public function __construct(
        string               $title,
        string               $description,
        int                  $volumeSolid,
        float                $massDensity,
        int                  $tdsDft,
        int                  $minDft,
        int                  $maxDft,
        int                  $applicationMinTemp,
        int                  $dryToTouch,
        int                  $minRecoatingInterval,
        int                  $maxRecoatingInterval,
        int                  $fullCure,
        Manufacturer         $manufacturer,
        CoatingSpecification $specification
    )
    {
        $this->id = UuidService::generate();
        $this->tags = new ArrayCollection();
        $this->specification = $specification;
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setVolumeSolid($volumeSolid);
        $this->setMassDensity($massDensity);
        $this->setTdsDft($tdsDft);
        $this->setMinDft($minDft);
        $this->setMaxDft($maxDft);
        $this->setApplicationMinTemp($applicationMinTemp);
        $this->setDryToTouch($dryToTouch);
        $this->setMinRecoatingInterval($minRecoatingInterval);
        $this->setMaxRecoatingInterval($maxRecoatingInterval);
        $this->setFullCure($fullCure);
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

    public function getId(): string
    {
        return $this->id;
    }

    public function addTag(CoatingTag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
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

    public function getMassDensity(): float
    {
        return $this->massDensity;
    }

    public function setMassDensity(float $massDensity): void
    {
        $this->massDensity = $massDensity;
        AssertService::greaterThan($this->massDensity, 0);

    }

    public function getTdsDft(): int
    {
        return $this->tdsDft;
    }

    public function setTdsDft(int $tdsDft): void
    {
        $this->tdsDft = $tdsDft;
        AssertService::greaterThan($this->tdsDft, 0);
    }

    public function getMinDft(): int
    {
        return $this->minDft;
    }

    public function setMinDft(int $minDft): void
    {
        $this->minDft = $minDft;
        AssertService::greaterThan($this->minDft, 10);

    }

    public function getMaxDft(): int
    {
        return $this->maxDft;
    }

    public function setMaxDft(int $maxDft): void
    {
        $this->maxDft = $maxDft;
        AssertService::lessThan($this->maxDft, 5000);
    }

    public function getApplicationMinTemp(): int
    {
        return $this->applicationMinTemp;
    }

    public function setApplicationMinTemp(int $applicationMinTemp): void
    {
        $this->applicationMinTemp = $applicationMinTemp;
    }

    public function getDryToTouch(): int
    {
        return $this->dryToTouch;
    }

    public function setDryToTouch(int $dryToTouch): void
    {
        $this->dryToTouch = $dryToTouch;
        AssertService::greaterThan($this->dryToTouch, 0);

    }

    public function getMinRecoatingInterval(): int
    {
        return $this->minRecoatingInterval;
    }

    public function setMinRecoatingInterval(int $minRecoatingInterval): void
    {
        $this->minRecoatingInterval = $minRecoatingInterval;
        AssertService::greaterThan($this->minRecoatingInterval, 0);

    }

    public function getMaxRecoatingInterval(): int
    {
        return $this->maxRecoatingInterval;
    }

    public function setMaxRecoatingInterval(int $maxRecoatingInterval): void
    {
        $this->maxRecoatingInterval = $maxRecoatingInterval;
    }

    public function getFullCure(): int
    {
        return $this->fullCure;
    }

    public function setFullCure(int $fullCure): void
    {
        $this->fullCure = $fullCure;
        AssertService::greaterThan($this->fullCure, 0);

    }

    public function getSpecification(): CoatingSpecification
    {
        return $this->specification;
    }

    public function setSpecification(CoatingSpecification $specification): void
    {
        $this->specification = $specification;
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function setTags(Collection $tags): void
    {
        $this->tags = $tags;
    }

    public function setVolumeSolid(int $volumeSolid): void
    {
        $this->volumeSolid = $volumeSolid;
        if ($volumeSolid < 1 || $volumeSolid > 100) {
            throw new \Exception("Volume solid must be between 1 and 100");
        }
    }

    public function getManufacturer(): Manufacturer
    {
        return $this->manufacturer;
    }

    public function setManufacturer(Manufacturer $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }
}