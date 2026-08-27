<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Domain\Security\SystemUser;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Аутентифицирует все консольные команды под системным принципалом (SystemUser).
 *
 * Зачем: авторизация операций живёт в Application-хендлерах (AccessControl), а у CLI
 * нет залогиненного пользователя — без токена isGranted() вернул бы false и любая
 * команда, диспатчащая мутацию через шину, упала бы Forbidden. Ставим SystemUser
 * (ROLE_SYSTEM) в TokenStorage до запуска команды.
 *
 * Только CLI: ConsoleEvents в web не летят. Если токен уже стоит (крайне редко) — не трогаем.
 */
final readonly class ConsoleAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onCommand'];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (null !== $this->tokenStorage->getToken()) {
            return;
        }

        $system = new SystemUser();
        $this->tokenStorage->setToken(new PreAuthenticatedToken($system, 'console', $system->getRoles()));
    }
}
