<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;

class CoatingDTOTransformer
{
    public function fromEntity(Coating $entity): CoatingDTO
    {
        $manufacturerDto = new ManufacturerDTO();
        $manufacturerDto->id = $entity->getManufacturer()->getId();
        $manufacturerDto->title = $entity->getManufacturer()->getTitle();
        $manufacturerDto->description = $entity->getManufacturer()->getDescription();

        $coatingTagDtos = [];
        foreach ($entity->getTags() as $tag) {
            $coatingTagDto = new CoatingTagDTO();
            $coatingTagDto->id = $tag->getId();
            $coatingTagDto->title = $tag->getTitle();
            $coatingTagDto->type = $tag->getType();

            $coatingTagDtos[] = $coatingTagDto;
        }

        $dftRange = $entity->getDftRange();
        $dftRangeDto = new DftRangeDTO();
        $dftRangeDto->min = (int) $dftRange->range->getMin();
        $dftRangeDto->max = (int) $dftRange->range->getMax();
        $dftRangeDto->tds_dft = $dftRange->tdsDft;
        $dftRangeDto->type = $dftRange->type->value;

        $dto = new CoatingDTO();
        $dto->id = $entity->getId();
        $dto->title = $entity->getTitle();
        $dto->description = $entity->getDescription();
        $dto->dryToTouch = $this->pointsFromSeries($entity->getDryToTouch());
        $dto->fullCure = $this->pointsFromSeries($entity->getFullCure());
        $dto->minRecoatingInterval = $this->treeDtoFromTree($entity->getMinRecoatingInterval());
        $dto->maxRecoatingInterval = null !== $entity->getMaxRecoatingInterval()
            ? $this->treeDtoFromTree($entity->getMaxRecoatingInterval())
            : null;
        $dto->applicationMinTemp = $entity->getApplicationMinTemp();
        $dto->dryingMaxTemp = $entity->getDryingMaxTemp();
        $dto->dftRange = $dftRangeDto;
        $dto->massDensity = $entity->getMassDensity();
        $dto->base = $entity->getBase()->value;
        $dto->volumeSolid = $entity->getVolumeSolid();
        $dto->pack = $entity->getPack();
        $dto->manufacturer = $manufacturerDto;
        $dto->thinner = $entity->getThinner();
        $dto->dryHeatExposure = $this->exposureDto($entity->getDryHeatExposure());
        $dto->immersionExposure = $this->exposureDto($entity->getImmersionExposure());
        $dto->tags = $coatingTagDtos;

        return $dto;
    }

    private function exposureDto(?ThermalExposureLimits $limits): ?ThermalExposureLimitsDTO
    {
        if (null === $limits) {
            return null;
        }
        $dto = new ThermalExposureLimitsDTO();
        $dto->continuous_min = $limits->continuousMin;
        $dto->continuous_max = $limits->continuousMax;
        $dto->peak_max = $limits->peakMax;
        $dto->peak_duration_minutes = $limits->peakDurationMinutes;

        return $dto;
    }

    /**
     * @param array<Coating> $coatings
     *
     * @return array<CoatingDTO>
     */
    public function fromEntityList(array $coatings): array
    {
        $coatingDTOs = [];
        foreach ($coatings as $coating) {
            $coatingDTOs[$coating->getId()] = $this->fromEntity($coating);
        }

        return $coatingDTOs;
    }

    private function treeDtoFromTree(RecoatingIntervalTree $tree): RecoatingIntervalTreeDTO
    {
        $dto = new RecoatingIntervalTreeDTO();
        $dto->default = $this->pointsFromSeries($tree->default);
        foreach ($tree->getChildren() as $key => $child) {
            $dto->branches[$key] = $this->treeDtoFromTree($child);
        }

        return $dto;
    }

    /** @return list<DryingTimePointDTO> */
    private function pointsFromSeries(DryingTimeSeries $series): array
    {
        return array_map(function (TimeAtTemperature $p): DryingTimePointDTO {
            $point = new DryingTimePointDTO();
            $point->temperature_at = $p->temperatureAt;
            $point->time_in_minutes = $p->timeInMinutes;
            $point->is_calculated = $p->isCalculated;

            return $point;
        }, $series->points);
    }
}
