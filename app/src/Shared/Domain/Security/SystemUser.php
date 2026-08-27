<?php

declare(strict_types=1);

namespace App\Shared\Domain\Security;

use App\Shared\Domain\Aggregate\ValueObject\Email;

/**
 * Синтетический принципал для консоли/крона. У CLI нет залогиненного пользователя,
 * а хендлеры авторизуют операции через AccessControl. Консольный подписчик
 * (ConsoleAuthenticationSubscriber) ставит этот принципал в TokenStorage перед
 * запуском команды, AccessControl короткозамыкает на ROLE_SYSTEM.
 *
 * В web недостижим: токен с ним ставится только на ConsoleEvents::COMMAND, ни один
 * firewall его не аутентифицирует. Не персистится — id/email синтетические.
 */
final class SystemUser implements AuthUserInterface
{
    public const ID = 'system';
    public const EMAIL = 'system@coating-monolith.local';

    private readonly Email $email;

    public function __construct()
    {
        $this->email = new Email(self::EMAIL);
    }

    public function getUlid(): string
    {
        return self::ID;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [Role::ROLE_SYSTEM];
    }

    public function getPassword(): ?string
    {
        return null;
    }

    public function getUserIdentifier(): string
    {
        return self::ID;
    }

    public function eraseCredentials(): void
    {
    }
}
