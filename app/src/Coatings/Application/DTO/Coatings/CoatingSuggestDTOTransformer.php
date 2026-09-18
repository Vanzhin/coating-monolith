<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\MixingRatio;

/**
 * Coating → лёгкий CoatingSuggestDTO. Единый источник проекции для typeahead-поиска
 * (SearchCoatings) и офлайн-выгрузки каталога (AllCoatingsForSuggest) — чтобы shape подсказки
 * жил в одном месте. Без тяжёлых связей (теги/цвета/системы/химстойкость) — их строит CoatingDTO.
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
