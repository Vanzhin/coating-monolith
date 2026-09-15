<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Twig;

use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Service\UnreadNotificationCounterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Число непрочитанных уведомлений текущего юзера для бейджа в шелле (колокол/пункт «Профиль»).
 * Ленивая: считает только если функция реально вызвана в шаблоне и юзер авторизован; результат
 * кэшируется на запрос.
 */
class NotificationsTwigExtension extends AbstractExtension
{
    private ?int $unread = null;

    public function __construct(
        private readonly AuthUserFetcherInterface $authUserFetcher,
        private readonly UnreadNotificationCounterInterface $unreadCounter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', [$this, 'unreadCount']),
        ];
    }

    public function unreadCount(): int
    {
        if (!$this->authUserFetcher->isAuthenticated()) {
            return 0;
        }

        return $this->unread ??= $this->unreadCounter->countForOwner($this->authUserFetcher->getAuthUserId());
    }
}
