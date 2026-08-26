<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\ChemicalResistance\Application\DTO\SubstanceMatchDTO;
use App\ChemicalResistance\Application\UseCase\Query\ListCoatingAssessments\CoatingAssessmentsPage;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemTitleDTO;
use App\Coatings\Application\DTO\Colors\ColorDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Application\DTO\Tags\TagDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\Gloss;
use App\Coatings\Domain\Aggregate\Coating\RecoatingInterpolationModel;

class CoatingDTO
{
    public string $id;
    public string $title;
    public string $description;
    public int $volumeSolid;
    public float $massDensity;

    /** ISO 12944-5 аббревиатура. */
    public string $base;

    public bool $isZincRich = false;

    /** Модель пересчёта мин.интервала перекрытия под фактическую толщину слоя (по умолчанию LINEAR). */
    public RecoatingInterpolationModel $recoatingInterpolationModel = RecoatingInterpolationModel::LINEAR;

    public function getBaseEnum(): ?CoatingBase
    {
        return CoatingBase::tryFrom($this->base);
    }

    public function getGlossEnum(): ?Gloss
    {
        return null !== $this->gloss ? Gloss::tryFrom($this->gloss) : null;
    }

    public DftRangeDTO $dftRange;

    public int $applicationMinTemp;

    /** Верхняя граница рабочего температурного диапазона. По умолчанию 50 °C. */
    public int $dryingMaxTemp = 50;

    /** @var list<DryingTimePointDTO> */
    public array $dryToTouch;

    /** @var list<DryingTimePointDTO> */
    public array $fullCure;

    public RecoatingIntervalTreeDTO $minRecoatingInterval;

    public ?RecoatingIntervalTreeDTO $maxRecoatingInterval = null;

    public float $pack;
    public ?string $thinner;
    public ManufacturerDTO $manufacturer;

    /** Температурные пределы эксплуатации: сухое тепло и погружение. Оба null'ятся,
     *  если данные не задокументированы (для immersion — если материал не рассчитан
     *  на погружение). Формат: {continuous_min, continuous_max, peak_max?, peak_duration_minutes?}. */
    public ?ThermalExposureLimitsDTO $dryHeatExposure = null;
    public ?ThermalExposureLimitsDTO $immersionExposure = null;

    /** @var TagDTO[] */
    public array $tags;

    /**
     * Возможные цвета покрытия — полные DTO (id+name+ral+hex), не id-список.
     *
     * @var list<ColorDTO>
     */
    public array $possibleColors = [];

    /** Степень блеска (enum-значение) — единственное на покрытие; null = не указан. */
    public ?string $gloss = null;

    /** Колеруемое покрытие (любой цвет). При true список возможных цветов может быть пустым. */
    public bool $isTintable = false;

    /** @var list<SubstanceMatchDTO> Вещества, совпавшие с поисковым запросом (пусто вне поискового контекста). */
    public array $matchedSubstances = [];

    /** @var list<CoatingSystemTitleDTO> системы, куда покрытие входит слоем */
    public array $systems = [];

    /** Первая страница оценок химической стойкости (preload для модального окна). */
    public ?CoatingAssessmentsPage $chemResistancePage = null;
}
