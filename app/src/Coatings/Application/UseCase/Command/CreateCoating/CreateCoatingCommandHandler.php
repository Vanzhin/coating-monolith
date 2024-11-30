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
        $coating = $this->coatingMaker->make(
            $command->dto->title,
            $command->dto->description,
            $command->dto->volumeSolid,
            $command->dto->massDensity,
            $command->dto->tdsDft,
            $command->dto->minDft,
            $command->dto->maxDft,
            $command->dto->applicationMinTemp,
            $command->dto->dryToTouch,
            $command->dto->minRecoatingInterval,
            $command->dto->maxRecoatingInterval,
            $command->dto->fullCure,
            $command->dto->manufacturer->id,
            array_map(function ($tag) {
                return $tag->id;
            }, $command->dto->tags),
            $command->dto->pack,
            $command->dto->thinner,
        );

        return new CreateCoatingCommandResult(
            $coating->getId()
        );
    }
}
