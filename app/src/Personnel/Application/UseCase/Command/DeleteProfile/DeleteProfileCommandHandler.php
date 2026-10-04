<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeleteProfile;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeleteProfileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private PersonnelAccessControl $access,
    ) {
    }

    public function __invoke(DeleteProfileCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $profile = $this->repository->findOneById($command->id);
        if (null === $profile) {
            throw new AppException('Профиль не найден.', Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($profile);
    }
}
