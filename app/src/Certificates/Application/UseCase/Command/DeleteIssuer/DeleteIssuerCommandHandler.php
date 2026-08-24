<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\DeleteIssuer;

use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeleteIssuerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private IssuerRepositoryInterface $repository,
    ) {
    }

    public function __invoke(DeleteIssuerCommand $command): void
    {
        $issuer = $this->repository->findOneById($command->id);
        if (null === $issuer) {
            throw new AppException('Издатель не найден.', Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($issuer);
    }
}
