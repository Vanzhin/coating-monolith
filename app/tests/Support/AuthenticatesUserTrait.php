<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\Aggregate\ValueObject\Email;
use App\Shared\Domain\Security\AuthUserInterface;
use App\Shared\Domain\Security\Role;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Аутентифицирует тестовый контекст обычным пользователем (ROLE_USER) с заданным ULID.
 * Нужно там, где AccessControl сравнивает владельца ресурса с текущим актором, а не
 * короткозамыкает на менеджера: ownership-кейсы (не-владелец должен получить отказ).
 * В отличие от AuthenticatesActorTrait (системный принципал), тут нет ROLE_SYSTEM.
 */
trait AuthenticatesUserTrait
{
    protected function authenticateAsUser(string $ulid): void
    {
        $user = new class($ulid) implements AuthUserInterface {
            public function __construct(private readonly string $ulid)
            {
            }

            public function getUlid(): string
            {
                return $this->ulid;
            }

            public function getEmail(): Email
            {
                return new Email($this->ulid.'@test.local');
            }

            /** @return list<string> */
            public function getRoles(): array
            {
                return [Role::ROLE_USER];
            }

            public function getPassword(): ?string
            {
                return null;
            }

            public function getUserIdentifier(): string
            {
                return $this->ulid;
            }

            public function eraseCredentials(): void
            {
            }
        };

        static::getContainer()
            ->get(TokenStorageInterface::class)
            ->setToken(new PreAuthenticatedToken($user, 'main', $user->getRoles()));
    }
}
