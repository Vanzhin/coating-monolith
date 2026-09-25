<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateCounterparty;

use App\Reports\Application\Service\AccessControl\CounterpartyAccessControl;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdateCounterpartyCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CounterpartyRepositoryInterface $repository,
        private CounterpartyAccessControl $access,
    ) {
    }

    public function __invoke(UpdateCounterpartyCommand $command): UpdateCounterpartyCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $counterparty = $this->repository->findOneById($command->id);
        if (null === $counterparty) {
            throw new AppException('Контрагент не найден.', Response::HTTP_NOT_FOUND);
        }

        // setTitle/setTin сами проверяют уникальность через spec, setTin — ещё и валидность ИНН (VO).
        $counterparty->setTitle($command->title);
        $counterparty->setTin($command->tin);
        $counterparty->setDescription($command->description);
        $this->repository->add($counterparty);

        return new UpdateCounterpartyCommandResult($counterparty->getId(), $counterparty->getTitle());
    }
}
