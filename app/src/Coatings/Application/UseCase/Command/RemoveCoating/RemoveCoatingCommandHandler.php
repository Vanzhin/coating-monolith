<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\RemoveCoating;

use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class RemoveCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(private CoatingRepositoryInterface $coatingRepository)
    {
    }

    public function __invoke(RemoveCoatingCommand $command): RemoveCoatingCommandResult
    {
        $manufacturer = $this->coatingRepository->findOneById($command->id);
        $this->coatingRepository->remove($manufacturer);

        return new RemoveCoatingCommandResult();
    }
}
