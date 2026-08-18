<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Event\CoatingMutated;
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
    /** Верхняя граница рабочего температурного диапазона (точки сушки/перекрытия должны быть ≤). */
    private int $dryingMaxTemp = 50;
    private DryingTimeSeries $dryToTouch;
    private DryingTimeSeries $fullCure;
    private RecoatingIntervalTree $minRecoatingInterval;
    private ?RecoatingIntervalTree $maxRecoatingInterval;
    private Manufacturer $manufacturer;
    private CoatingSpecification $specification;
    private float $pack;
    private ?string $thinner;

    /**
     * Температурные пределы эксплуатации в условиях сухого тепла / погружения.
     * Оба nullable: null = данных нет / погружение не применимо.
     */
    private ?ThermalExposureLimits $dryHeatExposure = null;
    private ?ThermalExposureLimits $immersionExposure = null;

    private bool $isZincRich = false;

    private RecoatingInterpolationModel $recoatingInterpolationModel = RecoatingInterpolationModel::LINEAR;

    /** @var Collection<Tag> */
    private Collection $tags;

    private ?Gloss $gloss = null;

    private bool $isTintable = false;

    /** @var Collection<Color> */
    private Collection $possibleColors;

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
        DryingTimeSeries $fullCure,
        RecoatingIntervalTree $minRecoatingInterval,
        ?RecoatingIntervalTree $maxRecoatingInterval,
        float $pack,
        ?string $thinner,
        Manufacturer $manufacturer,
        CoatingSpecification $specification,
        int $dryingMaxTemp = 50,
        bool $isZincRich = false,
        RecoatingInterpolationModel $recoatingInterpolationModel = RecoatingInterpolationModel::LINEAR,
    ) {
        $this->id = $id;
        $this->tags = new ArrayCollection();
        $this->possibleColors = new ArrayCollection();
        $this->specification = $specification;

        $this->setTitle($title);
        $this->setDescription($description);
        $this->setVolumeSolid($volumeSolid);
        $this->setMassDensity($massDensity);
        $this->setBase($base);
        $this->setDftRange($dftRange);
        // dryingMaxTemp ДО applicationMinTemp — иначе если applicationMinTemp >= default 50,
        // validateTemperatureRange бросит до того как dryingMaxTemp получит реальное значение.
        $this->setDryingMaxTemp($dryingMaxTemp);
        $this->setApplicationMinTemp($applicationMinTemp);
        $this->setDryToTouch($dryToTouch);
        $this->setFullCure($fullCure);
        $this->setMinRecoatingInterval($minRecoatingInterval);
        $this->setMaxRecoatingInterval($maxRecoatingInterval);
        $this->setPack($pack);
        $this->setThinner($thinner);
        $this->setManufacturer($manufacturer);
        $this->setIsZincRich($isZincRich);
        $this->setRecoatingInterpolationModel($recoatingInterpolationModel);
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getVolumeSolid(): int
    {
        return $this->volumeSolid;
    }

    public function getMassDensity(): float
    {
        return $this->massDensity;
    }

    public function getBase(): CoatingBase
    {
        return $this->base;
    }

    public function getDftRange(): DftRange
    {
        return $this->dftRange;
    }

    public function getApplicationMinTemp(): int
    {
        return $this->applicationMinTemp;
    }

    public function getDryingMaxTemp(): int
    {
        return $this->dryingMaxTemp;
    }

    public function getDryHeatExposure(): ?ThermalExposureLimits
    {
        return $this->dryHeatExposure;
    }

    public function getImmersionExposure(): ?ThermalExposureLimits
    {
        return $this->immersionExposure;
    }

    public function setDryHeatExposure(?ThermalExposureLimits $limits): void
    {
        $this->dryHeatExposure = $limits;
    }

    public function setImmersionExposure(?ThermalExposureLimits $limits): void
    {
        $this->immersionExposure = $limits;
    }

    public function getDryToTouch(): DryingTimeSeries
    {
        return $this->dryToTouch;
    }

    public function getFullCure(): DryingTimeSeries
    {
        return $this->fullCure;
    }

    public function getMinRecoatingInterval(): RecoatingIntervalTree
    {
        return $this->minRecoatingInterval;
    }

    public function getMaxRecoatingInterval(): ?RecoatingIntervalTree
    {
        return $this->maxRecoatingInterval;
    }

    public function getManufacturer(): Manufacturer
    {
        return $this->manufacturer;
    }

    public function getPack(): float
    {
        return $this->pack;
    }

    public function getThinner(): ?string
    {
        return $this->thinner;
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function setTitle(string $title): void
    {
        AssertService::maxLength($title, 100);
        $this->title = $title;
        $this->specification->uniqueTitleCoatingSpecification->satisfy($this);
        $this->raise(new CoatingMutated($this->getId()));
    }

    public function setDescription(string $description): void
    {
        AssertService::maxLength($description, 1500);
        $this->description = $description;
        $this->raise(new CoatingMutated($this->getId()));
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
        $this->raise(new CoatingMutated($this->getId()));
    }

    public function setBase(CoatingBase $base): void
    {
        $this->base = $base;
        $this->raise(new CoatingMutated($this->getId()));
    }

    public function setApplicationMinTemp(int $applicationMinTemp): void
    {
        $this->applicationMinTemp = $applicationMinTemp;
        $this->validateTemperatureRange();
        $this->raise(new CoatingMutated($this->getId()));
    }

    public function setDryingMaxTemp(int $dryingMaxTemp): void
    {
        $this->dryingMaxTemp = $dryingMaxTemp;
        $this->validateTemperatureRange();
    }

    public function setDryToTouch(DryingTimeSeries $dryToTouch): void
    {
        $this->dryToTouch = $dryToTouch;
        $this->validateTemperatureRange();
    }

    public function setFullCure(DryingTimeSeries $fullCure): void
    {
        $this->fullCure = $fullCure;
        $this->validateTemperatureRange();
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

    public function isZincRich(): bool
    {
        return $this->isZincRich;
    }

    public function setIsZincRich(bool $value): void
    {
        $this->isZincRich = $value;
        $this->raise(new CoatingMutated($this->getId()));
    }

    public function getRecoatingInterpolationModel(): RecoatingInterpolationModel
    {
        return $this->recoatingInterpolationModel;
    }

    public function setRecoatingInterpolationModel(RecoatingInterpolationModel $model): void
    {
        $this->recoatingInterpolationModel = $model;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    /** @param list<Tag> $tags */
    public function replaceTags(array $tags): void
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            $this->addTag($tag);
        }
    }

    public function getGloss(): ?Gloss
    {
        return $this->gloss;
    }

    public function setGloss(?Gloss $gloss): void
    {
        $this->gloss = $gloss;
        $this->raise(new CoatingMutated($this->getId()));
    }

    public function isTintable(): bool
    {
        return $this->isTintable;
    }

    /** @return Collection<Color> */
    public function getPossibleColors(): Collection
    {
        return $this->possibleColors;
    }

    /**
     * Атомарно задаёт колеруемость и список возможных цветов, проверяя инвариант
     * «не-колеруемое покрытие обязано иметь хотя бы один цвет» ровно один раз.
     * Отдельные сеттеры не годятся: дефолт (isTintable=false, пустой список) — транзитно
     * невалиден, и эагерная проверка сломала бы порядок конструирования.
     */
    public function applyColorScheme(bool $isTintable, Color ...$colors): void
    {
        if (!$isTintable && [] === $colors) {
            throw new AppException('У не-колеруемого покрытия должен быть хотя бы один возможный цвет.');
        }

        $this->isTintable = $isTintable;
        $this->possibleColors->clear();
        foreach ($colors as $color) {
            if (!$this->possibleColors->contains($color)) {
                $this->possibleColors->add($color);
            }
        }

        $this->raise(new CoatingMutated($this->getId()));
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

    public function setMinRecoatingInterval(RecoatingIntervalTree $minRecoatingInterval): void
    {
        (new CoatingRecoatingTreeValidator())->validate($minRecoatingInterval);
        $this->assertMinRecoatingHasBasePointAt20($minRecoatingInterval);
        $this->minRecoatingInterval = $minRecoatingInterval;
        $this->validateTemperatureRange();
        $this->raise(new CoatingMutated($this->getId()));
    }

    /**
     * Инвариант: default-корень минимального интервала перекрытия обязан содержать
     * явную точку ровно при 20 °C с валидной длительностью (>0).
     * Без такой точки интерполяция по толщине слоя системы невозможна — покрытие
     * не может участвовать в расчётах.
     */
    private function assertMinRecoatingHasBasePointAt20(RecoatingIntervalTree $tree): void
    {
        $point = $tree->default->getPoint(20);
        if (null === $point || $point->isCalculated) {
            throw new AppException('Для покрытия обязательна точка минимального интервала перекрытия при +20 °C.');
        }
        if (!$point->isExplicitPositiveDuration()) {
            throw new AppException('Точка минимального интервала перекрытия при +20 °C должна иметь положительное время.');
        }
    }

    public function setMaxRecoatingInterval(?RecoatingIntervalTree $maxRecoatingInterval): void
    {
        if (null !== $maxRecoatingInterval) {
            (new CoatingRecoatingTreeValidator())->validate($maxRecoatingInterval);
        }
        $this->maxRecoatingInterval = $maxRecoatingInterval;
        $this->validateTemperatureRange();
    }

    /**
     * Доменный инвариант температурного диапазона:
     *  1) applicationMinTemp < dryingMaxTemp (строго);
     *  2) каждая TimeAtTemperature точка во всех seriях лежит в [min, max].
     *
     * Вызывается из сеттеров обоих границ и из сеттеров всех 4 серий
     * (dryToTouch, fullCure, min/maxRecoatingInterval).
     * isset()-guard для applicationMinTemp — он set'ится позже dryingMaxTemp
     * в конструкторе, поэтому validate может вызваться когда ещё не все
     * поля проинициализированы.
     */
    private function validateTemperatureRange(): void
    {
        if (!isset($this->applicationMinTemp)) {
            return;
        }
        if ($this->applicationMinTemp >= $this->dryingMaxTemp) {
            throw new AppException(sprintf('Минимальная температура нанесения (%+d °C) должна быть строго меньше максимальной температуры сушки (%+d °C).', $this->applicationMinTemp, $this->dryingMaxTemp));
        }
        foreach ($this->collectAllSeries() as $label => $series) {
            foreach ($series->points as $point) {
                $t = $point->temperatureAt;
                if ($t < $this->applicationMinTemp || $t > $this->dryingMaxTemp) {
                    throw new AppException(sprintf('Температура %+d °C (%s) вне допустимого диапазона %+d..%+d °C.', $t, $label, $this->applicationMinTemp, $this->dryingMaxTemp));
                }
            }
        }
    }

    /**
     * @return \Generator<string, DryingTimeSeries>
     */
    private function collectAllSeries(): \Generator
    {
        if (isset($this->dryToTouch)) {
            yield 'сухой на отлип' => $this->dryToTouch;
        }
        if (isset($this->fullCure)) {
            yield 'полное отверждение' => $this->fullCure;
        }
        if (isset($this->minRecoatingInterval)) {
            yield from $this->walkRecoatingTree($this->minRecoatingInterval, 'мин. интервал перекрытия');
        }
        if (isset($this->maxRecoatingInterval) && null !== $this->maxRecoatingInterval) {
            yield from $this->walkRecoatingTree($this->maxRecoatingInterval, 'макс. интервал перекрытия');
        }
    }

    /**
     * @return \Generator<string, DryingTimeSeries>
     */
    private function walkRecoatingTree(RecoatingIntervalTree $tree, string $prefix): \Generator
    {
        yield $prefix => $tree->default;
        foreach ($tree->getChildren() as $key => $child) {
            yield from $this->walkRecoatingTree($child, $prefix.' → '.$key);
        }
    }

    /* --- БИЗНЕС-ЛОГИКА РАСЧЕТА ИНТЕРВАЛОВ (СТРОГАЯ ТИПИЗАЦИЯ) --- */

    /**
     * Минимальный интервал перекрытия ЭТОГО покрытия (в минутах) перед нанесением поверх него
     * $topcoat, в среде $env, при температуре $temperature (по умолчанию +20 °C).
     *
     * $actualDft — фактическая толщина, с которой нанесено САМО ЭТО покрытие (нижний слой);
     * интервал пересчитывается с эталонной tdsDft на неё. null → tdsDft (без пересчёта).
     * Это толщина именно этого покрытия, а не топкоата.
     *
     * Дерево проходится вглубь (RecoatingIntervalTree::find): правило под (среда, основание
     * топкоата) → дефолт среды → общий default покрытия. Null — если для температуры длительность
     * неизвестна (вне диапазона точек / unknown / unlimited).
     */
    public function minRecoatingFor(
        CoatingBase $topcoat,
        EnvironmentType $env,
        ?int $actualDft = null,
        int $temperature = 20,
    ): ?int {
        // find сам нормализует ключи (trim + mb_strtolower).
        $wait = $this->minRecoatingInterval->find($env->value, $topcoat->value)->series->getPoint($temperature);
        if (null === $wait || !$wait->hasPositiveDuration()) {
            return null;
        }

        return $this->recoatingInterpolationModel->interpolate(
            sourceDft: $this->dftRange->tdsDft,
            targetDft: $actualDft ?? $this->dftRange->tdsDft,
            sourceMinutes: $wait->timeInMinutes,
        );
    }

    public function maxRecoatingFor(EnvironmentType $env, CoatingBase $topcoat): ?DryingTimeSeries
    {
        return $this->maxRecoatingInterval?->find($env->value, $topcoat->value)->series;
    }

    public function maxRecoatingPointAt(EnvironmentType $env, CoatingBase $topcoat, int $temperature): ?TimeAtTemperature
    {
        return $this->maxRecoatingFor($env, $topcoat)?->getPoint($temperature);
    }

    /* --- БЕЗОПАСНОЕ ДОБАВЛЕНИЕ ИСКЛЮЧЕНИЙ ЧЕРЕЗ КЛОНИРОВАНИЕ СТРУКТУРЫ --- */

    public function addMinRecoatingFor(EnvironmentType $env, CoatingBase $base, DryingTimeSeries $timeSeries, ?DryingTimeSeries $envDefault = null): void
    {
        $this->setMinRecoatingInterval(
            $this->withRecoatingFor($this->minRecoatingInterval, $env, $base, $timeSeries, $envDefault),
        );
    }

    public function addMaxRecoatingFor(EnvironmentType $env, CoatingBase $base, DryingTimeSeries $timeSeries, ?DryingTimeSeries $envDefault = null): void
    {
        if (null === $this->maxRecoatingInterval) {
            throw new AppException('Нельзя добавить правило для максимального интервала: максимальный интервал равен null.');
        }
        $this->setMaxRecoatingInterval(
            $this->withRecoatingFor($this->maxRecoatingInterval, $env, $base, $timeSeries, $envDefault),
        );
    }

    /**
     * Возвращает новое дерево с правилом перекрытия для пары (env, base).
     * Ключи нормализуются внутри RecoatingIntervalTree (findNode/конструктор).
     */
    private function withRecoatingFor(
        RecoatingIntervalTree $tree,
        EnvironmentType $env,
        CoatingBase $base,
        DryingTimeSeries $series,
        ?DryingTimeSeries $envDefault,
    ): RecoatingIntervalTree {
        $envNode = $tree->findNode($env->value)
            ?? new RecoatingIntervalTree($envDefault ?? $tree->default, $env->value);

        return $tree->withChild($envNode->withChild(new RecoatingIntervalTree($series, $base->value)));
    }
}
