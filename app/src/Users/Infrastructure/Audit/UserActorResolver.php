<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Audit;

use App\Shared\Domain\Audit\ActorResolverInterface;
use App\Shared\Domain\Security\SystemUser;
use App\Users\Domain\Repository\UserRepositoryInterface;

/** Актор аудита → email юзера; системный принципал → «Система»; юзер удалён → сырой id. */
readonly class UserActorResolver implements ActorResolverInterface
{
    public function __construct(private UserRepositoryInterface $userRepository)
    {
    }

    public function resolve(string $actorId): string
    {
        if (SystemUser::ID === $actorId) {
            return 'Система';
        }

        $user = $this->userRepository->getByUlid($actorId);

        return null !== $user ? (string) $user->getEmail() : $actorId;
    }
}
