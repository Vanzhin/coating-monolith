<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Counterparty\Specification;

use App\Reports\Domain\Aggregate\Counterparty\Counterparty;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Domain\Specification\SpecificationInterface;
use App\Shared\Infrastructure\Exception\AppException;

class UniqueTinCounterpartySpecification implements SpecificationInterface
{
    public function __construct(private readonly CounterpartyRepositoryInterface $repository)
    {
    }

    public function satisfy(Counterparty $counterparty): void
    {
        $exist = $this->repository->findOneByTin($counterparty->getTin());
        if (null !== $exist && $exist->getId() !== $counterparty->getId()) {
            throw new AppException(sprintf('Контрагент с ИНН «%s» уже существует.', $counterparty->getTin()));
        }
    }
}
