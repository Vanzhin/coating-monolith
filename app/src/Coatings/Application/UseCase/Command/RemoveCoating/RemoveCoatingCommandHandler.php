<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveCoating;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

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

        $coating = $this->coatingRepository->findOneById($command->id)
            ?? throw new AppException('Покрытие не найдено.');

        try {
            $this->coatingRepository->remove($coating);
        } catch (ForeignKeyConstraintViolationException) {
            // FK: покрытие используется в системах — доменное сообщение вместо голого 500.
            throw new AppException('Покрытие используется в системах покрытий, удаление невозможно.');
        }

        return new RemoveCoatingCommandResult();
    }
}
