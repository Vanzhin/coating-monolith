<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

class Coating extends Aggregate
{
    public const PROTECTION_TYPE = 'CoatingProtectionType';
    public const COAT_TYPE = 'CoatingCoatType';

    public readonly Uuid $id;
    private string $title;
    private string $description;
    private int $volumeSolid;
    private float $massDensity;
    private CoatingBase $base;
    private DftRange $dftRange;
    private int $applicationMinTemp;
    private DryingTimeSeries $dryToTouch;
    private float $minRecoatingInterval;
    private ?float $maxRecoatingInterval;
    private DryingTimeSeries $fullCure;
    private Manufacturer $manufacturer;
    private CoatingSpecification $specification;
    private float $pack;
    private ?string $thinner;

    /** @var Collection<CoatingTag> */
    private Collection $tags;

    public function __construct(
        Uuid $id,
        string $title,
        string $description,
        int $volumeSolid,
        float $massDensity,
        CoatingBase $base,
        DftRange $dftRange,
        int $applicationMinTemp,
        DryingTimeSeries $dryToTouch,
        float $minRecoatingInterval,
        ?float $maxRecoatingInterval,
        DryingTimeSeries $fullCure,
        float $pack,
        ?string $thinner,
        Manufacturer $manufacturer,
        CoatingSpecification $specification,
    ) {
        $this->id = $id;
        $this->tags = new ArrayCollection();
        $this->specification = $specification;

        $this->setTitle($title);
        $this->setDescription($description);
        $this->setVolumeSolid($volumeSolid);
        $this->setMassDensity($massDensity);
        $this->setBase($base);
        $this->setDftRange($dftRange);
        $this->setApplicationMinTemp($applicationMinTemp);
        $this->setDryToTouch($dryToTouch);
        $this->setRecoatingIntervalBounds($minRecoatingInterval, $maxRecoatingInterval);
        $this->setFullCure($fullCure);
        $this->setPack($pack);
        $this->setThinner($thinner);
        $this->setManufacturer($manufacturer);
    }

    public function getId(): string { return $this->id->toRfc4122(); }

    public function getTitle(): string { return $this->title; }

    public function getDescription(): string { return $this->description; }

    public function getVolumeSolid(): int { return $this->volumeSolid; }

    public function getMassDensity(): float { return $this->massDensity; }

    public function getBase(): CoatingBase { return $this->base; }

    public function getDftRange(): DftRange { return $this->dftRange; }

    public function getApplicationMinTemp(): int { return $this->applicationMinTemp; }

    public function getDryToTouch(): DryingTimeSeries { return $this->dryToTouch; }

    public function getMinRecoatingInterval(): float { return $this->minRecoatingInterval; }

    public function getMaxRecoatingInterval(): ?float { return $this->maxRecoatingInterval; }

    public function getFullCure(): DryingTimeSeries { return $this->fullCure; }

    public function getManufacturer(): Manufacturer { return $this->manufacturer; }

    public function getPack(): float { return $this->pack; }

    public function getThinner(): ?string { return $this->thinner; }

    public function getTags(): Collection { return $this->tags; }

    public function setTitle(string $title): void
    {
        AssertService::maxLength($title, 100);
        $this->title = $title;
        $this->specification->uniqueTitleCoatingSpecification->satisfy($this);
    }

    public function setDescription(string $description): void
    {
        AssertService::maxLength($description, 1500);
        $this->description = $description;
    }

    public function setVolumeSolid(int $volumeSolid): void
    {
        if ($volumeSolid < 1 || $volumeSolid > 100) {
            throw new AppException('Сухой остаток (volumeSolid) должен быть в диапазоне 1..100.');
        }
        $this->volumeSolid = $volumeSolid;
    }

    public function setMassDensity(float $massDensity): void
    {
        AssertService::greaterThanEq($massDensity, 0);
        $this->massDensity = $massDensity;
    }

    public function setDftRange(DftRange $dftRange): void
    {
        $this->dftRange = $dftRange;
    }

    public function setBase(CoatingBase $base): void
    {
        $this->base = $base;
    }

    public function setApplicationMinTemp(int $applicationMinTemp): void
    {
        $this->applicationMinTemp = $applicationMinTemp;
    }

    public function setDryToTouch(DryingTimeSeries $dryToTouch): void
    {
        $this->dryToTouch = $dryToTouch;
    }

    public function setFullCure(DryingTimeSeries $fullCure): void
    {
        $this->fullCure = $fullCure;
    }

    public function setPack(float $pack): void
    {
        if ($pack < 1 || $pack > 1000) {
            throw new AppException('Упаковка (pack) должна быть в диапазоне 1..1000.');
        }
        $this->pack = $pack;
    }

    public function setThinner(?string $thinner): void
    {
        AssertService::maxLength($thinner, 100);
        $this->thinner = $thinner;
    }

    public function setManufacturer(Manufacturer $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }

    /** Можно ли это покрытие наносить поверх $primer (делегирует в основание). */
    public function canBeAppliedOnTopOf(self $primer): bool
    {
        return $this->base->canBeAppliedOnTopOf($primer->base);
    }

    /** Можно ли поверх этого покрытия нанести $topCoat (делегирует в основание). */
    public function canBecoveredBy(self $topCoat): bool
    {
        return $this->base->canBecoveredBy($topCoat->base);
    }

    /**
     * Атомарно задаёт пару (min, max). null в max означает, что верхней границы нет.
     * Только так извне можно изменить интервал перекрытия — чтобы инвариант min <= max
     * нельзя было нарушить временным промежуточным состоянием.
     */
    public function setRecoatingIntervalBounds(float $min, ?float $max): void
    {
        AssertService::greaterThanEq($min, 0);
        if ($max !== null) {
            AssertService::greaterThanEq($max, 0);
            if ($min > $max) {
                throw new AppException(sprintf(
                    'Минимальный интервал перекрытия (%g) не может превышать максимальный (%g).',
                    $min,
                    $max,
                ));
            }
        }
        $this->minRecoatingInterval = $min;
        $this->maxRecoatingInterval = $max;
    }

    public function addTag(CoatingTag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(CoatingTag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    /**
     * @param CoatingTag[] $tags
     */
    public function replaceTags(array $tags): void
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            $this->addTag($tag);
        }
    }
}
