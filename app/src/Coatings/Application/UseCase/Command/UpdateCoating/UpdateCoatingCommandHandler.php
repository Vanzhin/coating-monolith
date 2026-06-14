<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoating;

use App\Coatings\Application\DTO\Coatings\DftRangeDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Coatings\Domain\Service\CoatingTagFetcher;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;

readonly class UpdateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface      $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingTagFetcher               $coatingTagFetcher,
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
        if (isset($dto->applicationMinTemp)) {
            $coating->setApplicationMinTemp($dto->applicationMinTemp);
        }
        if (!empty($dto->pack)) {
            $coating->setPack($dto->pack);
        }

        if (!empty($dto->minRecoatingInterval)) {
            $coating->setMinRecoatingInterval($this->buildDryingTimeSeries($dto->minRecoatingInterval));
        }
        // maxRecoatingInterval=null означает «без верхней границы»; пустой массив трактуем так же.
        $coating->setMaxRecoatingInterval(
            empty($dto->maxRecoatingInterval)
                ? null
                : $this->buildDryingTimeSeries($dto->maxRecoatingInterval),
        );

        $coating->setThinner($dto->thinner ?? null);

        if (!empty($dto->tags)) {
            $tags = [];
            foreach ($dto->tags as $coatingTagDTO) {
                $tags[] = $this->coatingTagFetcher->getRequiredTag($coatingTagDTO->id);
            }
            $coating->replaceTags($tags);
        }

        $this->coatingRepository->add($coating);

        return new UpdateCoatingCommandResult();
    }

    private function buildDftRange(DftRangeDTO $range): DftRange
    {
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
