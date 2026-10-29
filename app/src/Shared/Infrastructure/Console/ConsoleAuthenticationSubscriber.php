<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Domain\Security\SystemUser;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Аутентифицирует всё, что бежит вне web, под системным принципалом (SystemUser).
 *
 * Зачем: авторизация операций живёт в Application-хендлерах (AccessControl), а у CLI/воркера
 * нет залогиненного пользователя — без токена isGranted() вернул бы false и любая операция,
 * диспатчащая мутацию через шину, упала бы Forbidden. Ставим SystemUser (ROLE_SYSTEM) в TokenStorage.
 *
 * Две точки входа, обе CLI (web-события сюда не летят):
 *  - ConsoleEvents::COMMAND — старт любой консольной команды (одноразовой).
 *  - WorkerMessageReceivedEvent — ПЕРЕД каждым сообщением messenger-воркера. Нужно отдельно:
 *    messenger после каждого сообщения ресетит сервисы с тегом kernel.reset, а security.token_storage
 *    помечен ['method' => 'setToken'] → токен обнуляется. Без пере-взвода на каждое сообщение
 *    долгоживущий `messenger:consume` (Scheduler, async) терял бы системного актора после первого
 *    сообщения, и гейты canManage() валились бы Forbidden со второго тика.
 *
 * Если токен уже стоит — не трогаем.
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
        return [
            ConsoleEvents::COMMAND => 'authenticateSystem',
            WorkerMessageReceivedEvent::class => 'authenticateSystem',
        ];
    }

    public function authenticateSystem(): void
    {
        if (null !== $this->tokenStorage->getToken()) {
            return;
        }

        $system = new SystemUser();
        $this->tokenStorage->setToken(new PreAuthenticatedToken($system, 'console', $system->getRoles()));
    }
}
