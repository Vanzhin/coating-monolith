<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Service;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Factory\CoatingFactory;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;


final readonly class CoatingMaker
{
    public function __construct(
        private CoatingFactory                  $coatingFactory,
        private CoatingRepositoryInterface      $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingTagFetcher $coatingTagFetcher
    )
    {
    }

    public function make(
        string $title,
        string $description,
        int    $volumeSolid,
        float  $massDensity,
        int    $tdsDft,
        int    $minDft,
        int    $maxDft,
        int    $applicationMinTemp,
        int    $dryToTouch,
        int    $minRecoatingInterval,
        int    $maxRecoatingInterval,
        int    $fullCure,
        string $manufacturerId,
        array $coatingTagIds
    ): Coating
    {
        $manufacturer = $this->manufacturerRepository->findOneById($manufacturerId);
        $coating = $this->coatingFactory->create(
            $title, $description, $volumeSolid, $massDensity, $tdsDft, $minDft, $maxDft, $applicationMinTemp,
            $dryToTouch, $minRecoatingInterval, $maxRecoatingInterval, $fullCure, $manufacturer);

        foreach ($coatingTagIds as $coatingTagId) {
            $coating->addTag($this->coatingTagFetcher->getRequiredTag($coatingTagId));
        }
        $this->coatingRepository->add($coating);

        return $coating;
    }

}