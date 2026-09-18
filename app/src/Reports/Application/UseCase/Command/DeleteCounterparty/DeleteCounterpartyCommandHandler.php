<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\DeleteCounterparty;

use App\Reports\Application\Service\AccessControl\CounterpartyAccessControl;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeleteCounterpartyCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CounterpartyRepositoryInterface $repository,
        private CounterpartyAccessControl $access,
    ) {
    }

    public function __invoke(DeleteCounterpartyCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $counterparty = $this->repository->findOneById($command->id);
        if (null === $counterparty) {
            throw new AppException('Контрагент не найден.', Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($counterparty);
    }
}
