<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\CommercialProposal;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;
use App\Users\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class CommercialProposal extends Aggregate
{
    private readonly string $id;
    private readonly string $number;
    private ?string $description;
    private ?string $basis;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $updatedAt;
    private User $owner;
    private CommercialProposalUnit $unit;
    private string $projectTitle;
    private float $projectArea;
    private ?string $projectStructureDescription;
    private int $loss;
    private ?CoatingSystemDurability $durability;
    private ?CoatingSystemCorrosiveCategory $category;
    private ?CoatingSystemSurfaceTreatment $treatment;
    private ?CoatingSystemApplicationMethod $method;


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
        float                $dryToTouch,
        float                $minRecoatingInterval,
        float                $maxRecoatingInterval,
        float                $fullCure,
        float                $pack,
        ?string              $thinner,
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
        $this->setPack($pack);
        $this->setThinner($thinner);
        $this->manufacturer = $manufacturer;

    }

    public function getId(): string
    {
        return $this->id;
    }

}