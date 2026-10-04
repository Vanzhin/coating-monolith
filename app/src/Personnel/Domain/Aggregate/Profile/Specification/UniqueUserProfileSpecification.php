<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile\Specification;

use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Domain\Specification\SpecificationInterface;
use App\Shared\Infrastructure\Exception\AppException;

class UniqueUserProfileSpecification implements SpecificationInterface
{
    public function __construct(private readonly ProfileRepositoryInterface $repository)
    {
    }

    public function satisfy(Profile $profile): void
    {
        $exist = $this->repository->findOneByUserUlid($profile->getUserUlid());
        if (null !== $exist && $exist->getId() !== $profile->getId()) {
            throw new AppException('Профиль для этого пользователя уже существует.');
        }
    }
}
