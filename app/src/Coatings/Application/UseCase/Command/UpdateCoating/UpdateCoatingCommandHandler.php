<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\UpdateCoating;

use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Service\CoatingMaker;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class UpdateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingMaker               $coatingMaker,
    )
    {
    }

    public function __invoke(UpdateCoatingCommand $command): UpdateCoatingCommandResult
    {
        $this->coatingMaker->make(
            $command->coatingDTO->title,
            $command->coatingDTO->description,
            $command->coatingDTO->volumeSolid,
            $command->coatingDTO->massDensity,
            $command->coatingDTO->tdsDft,
            $command->coatingDTO->minDft,
            $command->coatingDTO->maxDft,
            $command->coatingDTO->applicationMinTemp,
            $command->coatingDTO->dryToTouch,
            $command->coatingDTO->minRecoatingInterval,
            $command->coatingDTO->maxRecoatingInterval,
            $command->coatingDTO->fullCure,
            $command->coatingDTO->manufacturer->id,
            array_map(function ($tag) {
                return $tag->id;
            }, $command->coatingDTO->tags),
            $command->coatingDTO->pack,
            $this->coatingRepository->findOneById($command->coatingId)
        );

        return new UpdateCoatingCommandResult();
    }
}
