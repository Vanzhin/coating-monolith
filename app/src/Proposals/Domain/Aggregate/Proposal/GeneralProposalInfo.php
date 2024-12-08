<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Proposals\Domain\Aggregate\Proposal\Specification\GeneralProposalInfoSpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class GeneralProposalInfo extends Aggregate
{
    private readonly string $id;
    private string $number;
    private ?string $description;
    private ?string $basis;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $updatedAt;
    private string $ownerId;
    private GeneralProposalInfoUnit $unit;
    private string $projectTitle;
    private float $projectArea;
    private ?string $projectStructureDescription;
    private int $loss;
    private ?CoatingSystemDurability $durability;
    private ?CoatingSystemCorrosiveCategory $category;
    private ?CoatingSystemSurfaceTreatment $treatment;
    private ?CoatingSystemApplicationMethod $method;
    private GeneralProposalInfoSpecification $specification;


    /**
     * @var Collection<GeneralProposalInfoItem>
     */
    private Collection $coats;

    public function __construct(
        string                           $number,
        string                           $ownerId,
        GeneralProposalInfoUnit          $unit,
        string                           $projectTitle,
        float                            $projectArea,
        GeneralProposalInfoSpecification $specification,
        ?string                          $description = null,
        ?string                          $basis = null,
        ?string                          $projectStructureDescription = null,
        ?CoatingSystemDurability         $durability = null,
        ?CoatingSystemCorrosiveCategory  $category = null,
        ?CoatingSystemSurfaceTreatment   $treatment = null,
        ?CoatingSystemApplicationMethod  $method = null,
        int                              $loss = 30,
    )
    {
        $this->id = UuidService::generate();
        $this->specification = $specification;
        $this->setNumber($number);
        $this->setDescription($description);
        $this->setBasis($basis);
        $this->coats = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->ownerId = $ownerId;
        $this->unit = $unit;
        $this->setProjectTitle($projectTitle);
        $this->projectArea = $projectArea;
        $this->setProjectStructureDescription($projectStructureDescription);
        $this->setLoss($loss);
        $this->durability = $durability;
        $this->category = $category;
        $this->treatment = $treatment;
        $this->method = $method;


    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): void
    {
        $this->number = $number;
        AssertService::maxLength($this->number, 100);
        $this->specification->uniqueNumberProposalSpecification->satisfy($this);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        AssertService::maxLength($this->description, 750);

    }

    public function getBasis(): ?string
    {
        return $this->basis;
    }

    public function setBasis(?string $basis): void
    {
        $this->basis = $basis;
        AssertService::maxLength($this->basis, 750);

    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getUnit(): GeneralProposalInfoUnit
    {
        return $this->unit;
    }

    public function setUnit(GeneralProposalInfoUnit $unit): void
    {
        $this->unit = $unit;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function setOwnerId(string $ownerId): void
    {
        $this->ownerId = $ownerId;
    }

    public function getProjectTitle(): string
    {
        return $this->projectTitle;
    }

    public function setProjectTitle(string $projectTitle): void
    {
        $this->projectTitle = $projectTitle;
        AssertService::maxLength($this->projectTitle, 750);
    }

    public function getProjectArea(): float
    {
        return $this->projectArea;
    }

    public function setProjectArea(float $projectArea): void
    {
        $this->projectArea = $projectArea;
    }

    public function getProjectStructureDescription(): ?string
    {
        return $this->projectStructureDescription;
    }

    public function setProjectStructureDescription(?string $projectStructureDescription): void
    {
        $this->projectStructureDescription = $projectStructureDescription;
        AssertService::maxLength($this->projectStructureDescription, 750);

    }

    public function getLoss(): int
    {
        return $this->loss;
    }

    public function setLoss(int $loss): void
    {
        $this->loss = $loss;
        AssertService::greaterThanEq($this->loss, 0);
        AssertService::lessThanEq($this->loss, 100);
    }

    public function getDurability(): ?CoatingSystemDurability
    {
        return $this->durability;
    }

    public function setDurability(?CoatingSystemDurability $durability): void
    {
        $this->durability = $durability;
    }

    public function getCoats(): Collection
    {
        return $this->coats;
    }

    public function setCoats(Collection $coats): void
    {
        $this->coats = $coats;
    }

    public function getCategory(): ?CoatingSystemCorrosiveCategory
    {
        return $this->category;
    }

    public function setCategory(?CoatingSystemCorrosiveCategory $category): void
    {
        $this->category = $category;
    }

    public function getTreatment(): ?CoatingSystemSurfaceTreatment
    {
        return $this->treatment;
    }

    public function setTreatment(?CoatingSystemSurfaceTreatment $treatment): void
    {
        $this->treatment = $treatment;
    }

    public function getMethod(): ?CoatingSystemApplicationMethod
    {
        return $this->method;
    }

    public function setMethod(?CoatingSystemApplicationMethod $method): void
    {
        $this->method = $method;
    }

    public function addItem(GeneralProposalInfoItem $layer): void
    {
        if (!$this->coats->contains($layer)) {
            $this->coats->add($layer);
        }
    }

    public function isOwnedBy(string $ownerId): bool
    {
        return $this->ownerId === $ownerId;
    }
}