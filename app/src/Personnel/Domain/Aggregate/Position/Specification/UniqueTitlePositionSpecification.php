<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Position\Specification;

use App\Personnel\Domain\Aggregate\Position\Position;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Domain\Specification\SpecificationInterface;
use App\Shared\Infrastructure\Exception\AppException;

class UniqueTitlePositionSpecification implements SpecificationInterface
{
    public function __construct(private readonly PositionRepositoryInterface $repository)
    {
    }

    public function satisfy(Position $position): void
    {
        $exist = $this->repository->findOneByTitle($position->getTitle());
        if (null !== $exist && $exist->getId() !== $position->getId()) {
            throw new AppException(sprintf('Должность «%s» уже существует.', $position->getTitle()));
        }
    }
}
