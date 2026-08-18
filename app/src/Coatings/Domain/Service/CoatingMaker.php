<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\Gloss;
use App\Coatings\Domain\Aggregate\Coating\RecoatingInterpolationModel;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;

/**
 * Создаёт `Coating`, выдаёт UUID, добавляет теги, сохраняет.
 * Совмещает в себе бывшую `CoatingFactory` (фабричный слой убран как избыточный).
 */
final readonly class CoatingMaker
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private TagFetcher $coatingTagFetcher,
        private CoatingSpecification $coatingSpecification,
        private ColorRepositoryInterface $colorRepository,
    ) {
    }

    /**
     * @param list<string> $coatingTagIds
     */
    public function make(
        string $title,
        string $description,
        int $volumeSolid,
        float $massDensity,
        CoatingBase $base,
        DftRange $dftRange,
        int $applicationMinTemp,
        DryingTimeSeries $dryToTouch,
        DryingTimeSeries $fullCure,
        RecoatingIntervalTree $minRecoatingInterval,
        ?RecoatingIntervalTree $maxRecoatingInterval,
        string $manufacturerId,
        array $coatingTagIds,
        float $pack,
        ?string $thinner,
        int $dryingMaxTemp = 50,
        ?ThermalExposureLimits $dryHeatExposure = null,
        ?ThermalExposureLimits $immersionExposure = null,
        bool $isZincRich = false,
        RecoatingInterpolationModel $recoatingInterpolationModel = RecoatingInterpolationModel::LINEAR,
        StringCollection $colorIds = new StringCollection(),
        ?Gloss $gloss = null,
        bool $isTintable = false,
    ): Coating {
        $manufacturer = $this->manufacturerRepository->findOneById($manufacturerId);

        $coating = new Coating(
            UuidService::generateUuid(),
            $title,
            $description,
            $volumeSolid,
            $massDensity,
            $base,
            $dftRange,
            $applicationMinTemp,
            $dryToTouch,
            $fullCure,
            $minRecoatingInterval,
            $maxRecoatingInterval,
            $pack,
            $thinner,
            $manufacturer,
            $this->coatingSpecification,
            $dryingMaxTemp,
            $isZincRich,
            $recoatingInterpolationModel,
        );

        foreach ($coatingTagIds as $coatingTagId) {
            $coating->addTag($this->coatingTagFetcher->getRequiredTag($coatingTagId));
        }

        $colors = $this->colorRepository->findByIds($colorIds);
        $coating->applyColorScheme($isTintable, ...$colors);
        $coating->setGloss($gloss);

        // Температурные пределы эксплуатации выставляются ДО add(): repo.add
        // делает flush, после него сеттеры не персистятся без нового flush'a.
        $coating->setDryHeatExposure($dryHeatExposure);
        $coating->setImmersionExposure($immersionExposure);

        $this->coatingRepository->add($coating);

        return $coating;
    }
}
