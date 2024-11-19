<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\CreateCoating;

use App\Coatings\Domain\Service\CoatingMaker;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class CreateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingMaker $coatingMaker,
    )
    {
    }

    public function __invoke(CreateCoatingCommand $command): CreateCoatingCommandResult
    {
        $coating = $this->coatingMaker->make($command->title,
            $command->description,
            $command->volumeSolid,
            $command->massDensity,
            $command->tdsDft,
            $command->minDft,
            $command->maxDft,
            $command->applicationMinTemp,
            $command->dryToTouch,
            $command->minRecoatingInterval,
            $command->maxRecoatingInterval,
            $command->fullCure,
            $command->manufacturerId,
            $command->coatingTagIds
        );

        return new CreateCoatingCommandResult(
            $coating->getId()
        );
    }
}
