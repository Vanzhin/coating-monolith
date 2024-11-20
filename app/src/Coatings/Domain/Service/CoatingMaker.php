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
        private CoatingTagFetcher               $coatingTagFetcher
    )
    {
    }

    public function make(
        string   $title,
        string   $description,
        int      $volumeSolid,
        float    $massDensity,
        int      $tdsDft,
        int      $minDft,
        int      $maxDft,
        int      $applicationMinTemp,
        float    $dryToTouch,
        float    $minRecoatingInterval,
        float    $maxRecoatingInterval,
        float    $fullCure,
        string   $manufacturerId,
        array    $coatingTagIds,
        float    $pack,
        ?Coating $coating = null,
    ): Coating
    {
        $manufacturer = $this->manufacturerRepository->findOneById($manufacturerId);
        if (null === $coating) {
            $coating = $this->coatingFactory->create(
                $title, $description, $volumeSolid, $massDensity, $tdsDft, $minDft, $maxDft, $applicationMinTemp,
                $dryToTouch, $minRecoatingInterval, $maxRecoatingInterval, $fullCure, $manufacturer, $pack);
        } else {
            $coating->setTitle($title);
            $coating->setDescription($description);
            $coating->setVolumeSolid($volumeSolid);
            $coating->setMassDensity($massDensity);
            $coating->setTdsDft($tdsDft);
            $coating->setMinDft($minDft);
            $coating->setMaxDft($maxDft);
            $coating->setApplicationMinTemp($applicationMinTemp);
            $coating->setDryToTouch($dryToTouch);
            $coating->setMinRecoatingInterval($minRecoatingInterval);
            $coating->setMaxRecoatingInterval($maxRecoatingInterval);
            $coating->setFullCure($fullCure);
            $coating->setManufacturer($manufacturer);
            $coating->setPack($pack);
            $coating->getTags()->clear();
        }

        foreach ($coatingTagIds as $coatingTagId) {
            $coating->addTag($this->coatingTagFetcher->getRequiredTag($coatingTagId));
        }
        $this->coatingRepository->add($coating);

        return $coating;
    }

}