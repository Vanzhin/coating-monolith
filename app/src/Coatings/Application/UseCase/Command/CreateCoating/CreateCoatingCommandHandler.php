<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoating;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\ThermalExposureLimitsDTO;
use App\Coatings\Application\UseCase\Command\RecoatingTreeBuilder;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Service\CoatingMaker;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Infrastructure\Exception\AppException;

readonly class CreateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingMaker $coatingMaker,
        private RecoatingTreeBuilder $treeBuilder,
    ) {
    }

    public function __invoke(CreateCoatingCommand $command): CreateCoatingCommandResult
    {
        $dto = $command->dto;

        $coating = $this->coatingMaker->make(
            $dto->title,
            $dto->description,
            $dto->volumeSolid,
            $dto->massDensity,
            CoatingBase::from($dto->base),
            $this->buildDftRange($dto),
            $dto->applicationMinTemp,
            $this->buildDryingTimeSeries($dto->dryToTouch),
            $this->buildDryingTimeSeries($dto->fullCure),
            $this->treeBuilder->buildMinTree($dto->minRecoatingInterval)
                ?? throw new AppException('Минимальный интервал перекрытия обязателен.'),
            null !== $dto->maxRecoatingInterval
                ? $this->treeBuilder->build($dto->maxRecoatingInterval)
                : null,
            $dto->manufacturer->id,
            array_map(fn ($tag) => $tag->id, $dto->tags),
            $dto->pack,
            $dto->thinner,
            $dto->dryingMaxTemp,
            $this->buildExposure($dto->dryHeatExposure),
            $this->buildExposure($dto->immersionExposure),
        );

        return new CreateCoatingCommandResult($coating->getId());
    }

    private function buildExposure(?ThermalExposureLimitsDTO $dto): ?ThermalExposureLimits
    {
        if (null === $dto) {
            return null;
        }

        return new ThermalExposureLimits(
            $dto->continuous_min,
            $dto->continuous_max,
            $dto->peak_max,
            $dto->peak_duration_minutes,
        );
    }

    private function buildDftRange(CoatingDTO $dto): DftRange
    {
        $range = $dto->dftRange;

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
            fn (DryingTimePointDTO $p) => new TimeAtTemperature($p->temperature_at, $p->time_in_minutes),
            $points,
        ));
    }
}
