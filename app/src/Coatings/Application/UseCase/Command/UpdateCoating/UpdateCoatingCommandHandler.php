<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoating;

use App\Coatings\Application\DTO\Coatings\DftRangeDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\UseCase\Command\RecoatingTreeBuilder;
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
        // dryingMaxTemp ДО applicationMinTemp — расширяем «потолок» прежде чем
        // двигать «пол», иначе при app_min >= текущего drying_max validate бросит
        // на промежуточном состоянии до того как dryingMaxTemp обновится.
        if (isset($dto->dryingMaxTemp)) {
            $coating->setDryingMaxTemp($dto->dryingMaxTemp);
        }
        if (isset($dto->applicationMinTemp)) {
            $coating->setApplicationMinTemp($dto->applicationMinTemp);
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

    /** @param list<DryingTimePointDTO> $points */
    private function buildDryingTimeSeries(array $points): DryingTimeSeries
    {
        return new DryingTimeSeries(...array_map(
            fn(DryingTimePointDTO $p) => new TimeAtTemperature($p->temperature_at, $p->time_in_minutes),
            $points,
        ));
    }
}
