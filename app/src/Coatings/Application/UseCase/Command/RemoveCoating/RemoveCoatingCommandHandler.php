<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveCoating;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

readonly class RemoveCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(RemoveCoatingCommand $command): RemoveCoatingCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $manufacturer = $this->coatingRepository->findOneById($command->id);
        $this->coatingRepository->remove($manufacturer);

        return new RemoveCoatingCommandResult();
    }
}
