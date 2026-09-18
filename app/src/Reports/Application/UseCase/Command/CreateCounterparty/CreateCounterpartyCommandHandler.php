<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateCounterparty;

use App\Reports\Application\Service\AccessControl\CounterpartyAccessControl;
use App\Reports\Domain\Aggregate\Counterparty\Counterparty;
use App\Reports\Domain\Aggregate\Counterparty\Specification\CounterpartySpecification;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class CreateCounterpartyCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CounterpartyRepositoryInterface $repository,
        private CounterpartySpecification $specification,
        private CounterpartyAccessControl $access,
    ) {
    }

    public function __invoke(CreateCounterpartyCommand $command): CreateCounterpartyCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        // Уникальность title проверяет сам агрегат в setTitle (через spec) — отдельного satisfy не надо.
        $counterparty = new Counterparty(UuidService::generate(), $command->title, $this->specification, $command->description);
        $this->repository->add($counterparty);

        return new CreateCounterpartyCommandResult($counterparty->getId(), $counterparty->getTitle());
    }
}
