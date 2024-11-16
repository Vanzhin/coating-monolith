<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class CoatingTag extends Aggregate
{
    private readonly string $id;
    private string $title;
    private ?string $type;

    /**
     * @var Collection<Coating>
     */
    private Collection $coatings;

    public function __construct(
        string                  $title,
        CoatingTagSpecification $specification,
        string                  $type = null,
    )
    {
        $this->id = UuidService::generate();
        $this->specification = $specification;
        $this->coatings = new ArrayCollection();
        $this->setTitle($title);
        $this->setType($title);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        AssertService::maxLength($this->title, 100);
        $this->checkTitleAndType();
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
        AssertService::maxLength($this->type, 100);
        $this->checkTitleAndType();
    }

    public function addCoating(Coating $coating): void
    {
        if (!$this->coatings->contains($coating)) {
            $this->coatings->add($coating);
        }
    }

    private function checkTitleAndType(): void
    {
        AssertService::maxLength($this->title, 100);
        $this->specification->titleAndTypeCoatingTagSpecification->satisfy($this);
    }

}