<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\DeleteIssuer;

use App\Certificates\Application\Service\AccessControl\IssuerAccessControl;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeleteIssuerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private IssuerRepositoryInterface $repository,
        private IssuerAccessControl $access,
    ) {
    }

    public function __invoke(DeleteIssuerCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $issuer = $this->repository->findOneById($command->id);
        if (null === $issuer) {
            throw new AppException('Организация не найдена.', Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($issuer);
    }
}
