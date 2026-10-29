<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\GetUserIdByEmail;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;

/** Email отправителя → ulid пользователя (или null, если такого пользователя нет). */
final readonly class GetUserIdByEmailQueryHandler implements QueryHandlerInterface
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    public function __invoke(GetUserIdByEmailQuery $query): ?string
    {
        return $this->users->getByEmail($query->email)?->getUlid();
    }
}
