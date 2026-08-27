<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\Security\SystemUser;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Аутентифицирует тестовый контекст привилегированным актором, чтобы Application-хендлеры,
 * авторизующие операции через AccessControl, проходили. Для handler/UseCase-тестов без
 * HTTP-клиента (у них нет firewall'а, а значит и токена). Системный принципал = ROLE_SYSTEM,
 * как у консоли.
 */
trait AuthenticatesActorTrait
{
    protected function authenticateAsSystem(): void
    {
        $system = new SystemUser();
        static::getContainer()
            ->get(TokenStorageInterface::class)
            ->setToken(new PreAuthenticatedToken($system, 'test', $system->getRoles()));
    }
}
