<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Domain\Service\UuidService;

/**
 * Создаёт `Coating`, выдаёт UUID, добавляет теги, сохраняет.
 * Совмещает в себе бывшую `CoatingFactory` (фабричный слой убран как избыточный).
 */
final readonly class CoatingMaker
{
    public function __construct(
        private CoatingRepositoryInterface      $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingTagFetcher               $coatingTagFetcher,
        private CoatingSpecification            $coatingSpecification,
    ) {
    }

    public function make(
        string            $title,
        string            $description,
        int               $volumeSolid,
        float             $massDensity,
        CoatingBase       $base,
        DftRange          $dftRange,
        int               $applicationMinTemp,
        DryingTimeSeries  $dryToTouch,
        DryingTimeSeries  $fullCure,
        DryingTimeSeries  $minRecoatingInterval,
        ?DryingTimeSeries $maxRecoatingInterval,
        string            $manufacturerId,
        array             $coatingTagIds,
        float             $pack,
        ?string           $thinner,
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
        );

        foreach ($coatingTagIds as $coatingTagId) {
            $coating->addTag($this->coatingTagFetcher->getRequiredTag($coatingTagId));
        }

        $this->coatingRepository->add($coating);

        return $coating;
    }
}
