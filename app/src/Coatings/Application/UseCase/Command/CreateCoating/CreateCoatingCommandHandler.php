<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoating;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Service\CoatingMaker;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;

readonly class CreateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingMaker $coatingMaker,
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
            $this->buildDryingTimeSeries($dto->minRecoatingInterval),
            $dto->maxRecoatingInterval !== null
                ? $this->buildDryingTimeSeries($dto->maxRecoatingInterval)
                : null,
            $dto->manufacturer->id,
            array_map(fn($tag) => $tag->id, $dto->tags),
            $dto->pack,
            $dto->thinner,
        );

        return new CreateCoatingCommandResult($coating->getId());
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

    /**
     * @param list<DryingTimePointDTO> $points
     */
    private function buildDryingTimeSeries(array $points): DryingTimeSeries
    {
        $timePoints = array_map(
            fn(DryingTimePointDTO $point) => new TimeAtTemperature(
                $point->temperature_at,
                $point->time_in_minutes,
            ),
            $points,
        );

        return new DryingTimeSeries(...$timePoints);
    }
}
