<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoating;

use App\Coatings\Application\DTO\Coatings\DftRangeDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\ThermalExposureLimitsDTO;
use App\Coatings\Application\UseCase\Command\RecoatingTreeBuilder;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Coatings\Domain\Service\CoatingTagFetcher;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Infrastructure\Exception\AppException;

readonly class UpdateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface      $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingTagFetcher               $coatingTagFetcher,
        private RecoatingTreeBuilder            $treeBuilder,
    ) {
    }

    public function __invoke(UpdateCoatingCommand $command): UpdateCoatingCommandResult
    {
        $coating = $this->coatingRepository->findOneById($command->coatingId);
        $dto = $command->coatingDTO;

        if ($dto->manufacturer) {
            $coating->setManufacturer(
                $this->manufacturerRepository->findOneById($dto->manufacturer->id),
            );
        }

        if ($dto->title) {
            $coating->setTitle($dto->title);
        }
        if ($dto->description) {
            $coating->setDescription($dto->description);
        }

        if (isset($dto->dftRange)) {
            $coating->setDftRange($this->buildDftRange($dto->dftRange));
        }

        // Температурные границы ДО любых series-сеттеров. Если пользователь
        // расширяет диапазон (например max 50→80) И добавляет точку 75°C —
        // series-сеттер validate'нул бы 75 против ещё СТАРОГО max=50 и бросил
        // AppException до того как dryingMaxTemp успеет обновиться.
        // dryingMaxTemp перед applicationMinTemp — расширяем «потолок» прежде
        // чем двигать «пол» (см. setApplicationMinTemp validate).
        // Edge-case: narrowing-with-fixed-points (max 50→40 при существующих
        // точках 50°C, которые в этом же UPDATE двигаются в 40°C) — здесь
        // setDryingMaxTemp(40) всё равно бросит на старых точках. Это редкий
        // сценарий, фикс — два сохранения (сначала точки, потом узить границу).
        if (isset($dto->dryingMaxTemp)) {
            $coating->setDryingMaxTemp($dto->dryingMaxTemp);
        }
        if (isset($dto->applicationMinTemp)) {
            $coating->setApplicationMinTemp($dto->applicationMinTemp);
        }

        if (!empty($dto->dryToTouch)) {
            $coating->setDryToTouch($this->buildDryingTimeSeries($dto->dryToTouch));
        }
        if (!empty($dto->fullCure)) {
            $coating->setFullCure($this->buildDryingTimeSeries($dto->fullCure));
        }

        if (!empty($dto->volumeSolid)) {
            $coating->setVolumeSolid($dto->volumeSolid);
        }
        if (!empty($dto->massDensity)) {
            $coating->setMassDensity($dto->massDensity);
        }
        if (!empty($dto->base) && CoatingBase::tryFrom($dto->base) !== null) {
            $coating->setBase(CoatingBase::from($dto->base));
        }
        if (!empty($dto->pack)) {
            $coating->setPack($dto->pack);
        }

        $minTree = $this->treeBuilder->buildMinTree($dto->minRecoatingInterval)
            ?? throw new AppException('Минимальный интервал перекрытия обязателен.');
        $coating->setMinRecoatingInterval($minTree);

        // maxRecoatingInterval=null означает «без верхней границы».
        $coating->setMaxRecoatingInterval(
            $dto->maxRecoatingInterval !== null
                ? $this->treeBuilder->build($dto->maxRecoatingInterval)
                : null,
        );

        $coating->setThinner($dto->thinner ?? null);

        if (!empty($dto->tags)) {
            $tags = [];
            foreach ($dto->tags as $coatingTagDTO) {
                $tags[] = $this->coatingTagFetcher->getRequiredTag($coatingTagDTO->id);
            }
            $coating->replaceTags($tags);
        }

        $coating->setDryHeatExposure($this->buildExposure($dto->dryHeatExposure));
        $coating->setImmersionExposure($this->buildExposure($dto->immersionExposure));

        $this->coatingRepository->add($coating);

        return new UpdateCoatingCommandResult();
    }

    private function buildExposure(?ThermalExposureLimitsDTO $dto): ?ThermalExposureLimits
    {
        if ($dto === null) {
            return null;
        }
        return new ThermalExposureLimits(
            $dto->continuous_min,
            $dto->continuous_max,
            $dto->peak_max,
            $dto->peak_duration_minutes,
        );
    }

    private function buildDftRange(DftRangeDTO $range): DftRange
    {
        return new DftRange(
            new PositiveNumberRange($range->min, $range->max),
            $range->tds_dft,
            ThicknessType::from($range->type),
        );
    }

    /** @param list<DryingTimePointDTO> $points */
    private function buildDryingTimeSeries(array $points): DryingTimeSeries
    {
        return new DryingTimeSeries(...array_map(
            fn(DryingTimePointDTO $p) => new TimeAtTemperature($p->temperature_at, $p->time_in_minutes),
            $points,
        ));
    }
}
