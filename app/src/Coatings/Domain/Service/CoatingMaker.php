<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Factory\CoatingFactory;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;

final readonly class CoatingMaker
{
    public function __construct(
        private CoatingFactory                  $coatingFactory,
        private CoatingRepositoryInterface      $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingTagFetcher               $coatingTagFetcher,
    ) {
    }

    public function make(
        string           $title,
        string           $description,
        int              $volumeSolid,
        float            $massDensity,
        DftRange         $dftRange,
        int              $applicationMinTemp,
        DryingTimeSeries $dryToTouch,
        float            $minRecoatingInterval,
        ?float           $maxRecoatingInterval,
        DryingTimeSeries $fullCure,
        string           $manufacturerId,
        array            $coatingTagIds,
        float            $pack,
        ?string          $thinner,
    ): Coating {
        $manufacturer = $this->manufacturerRepository->findOneById($manufacturerId);

        $coating = $this->coatingFactory->create(
            $title,
            $description,
            $volumeSolid,
            $massDensity,
            $dftRange,
            $applicationMinTemp,
            $dryToTouch,
            $minRecoatingInterval,
            $maxRecoatingInterval,
            $fullCure,
            $manufacturer,
            $pack,
            $thinner,
        );

        foreach ($coatingTagIds as $coatingTagId) {
            $coating->addTag($this->coatingTagFetcher->getRequiredTag($coatingTagId));
        }

        $this->coatingRepository->add($coating);

        return $coating;
    }
}
