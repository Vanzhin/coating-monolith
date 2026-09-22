<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\MixingRatio;

/**
 * Лёгкий CoatingSuggestDTO из покрытия: только то, что нужно typeahead-подсказке и калькуляторам
 * (сухой остаток, фасовка, плотность, соотношение смешивания). Без тяжёлых связей. Общий для поиска
 * (typeahead) и endpoint'а контекста покрытия по id (засев калькуляторов на странице заполнения).
 */
class CoatingSuggestDTOTransformer
{
    public function fromEntity(Coating $coating): CoatingSuggestDTO
    {
        $dftMin = (int) $coating->getDftRange()->range->getMin();
        $dftMax = (int) $coating->getDftRange()->range->getMax();
        $base = $coating->getBase()->value;

        $dto = new CoatingSuggestDTO();
        $dto->id = $coating->getId();
        $dto->title = sprintf('%s (%s, %d–%d мкм)', $coating->getTitle(), $base, $dftMin, $dftMax);
        $dto->base = $base;
        $dto->dftMin = $dftMin;
        $dto->dftMax = $dftMax;
        $dto->volumeSolid = $coating->getVolumeSolid();
        $dto->pack = $coating->getPack();
        $dto->massDensity = $coating->getMassDensity();
        $dto->mixingRatio = $this->mixingRatioDto($coating->getMixingRatio());

        return $dto;
    }

    private function mixingRatioDto(?MixingRatio $mixingRatio): ?MixingRatioDTO
    {
        if (null === $mixingRatio) {
            return null;
        }

        $dto = new MixingRatioDTO();
        $dto->volume = $mixingRatio->getByVolume()?->getParts();
        $dto->mass = $mixingRatio->getByMass()?->getParts();

        return $dto;
    }
}
