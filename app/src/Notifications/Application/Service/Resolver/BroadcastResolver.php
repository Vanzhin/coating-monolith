<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\NotificationAudienceProviderInterface;
use App\Notifications\Domain\Service\RecipientResolverInterface;
use App\Notifications\Domain\Type\ResolverKey;

/** Адресаты — все пользователи (широковещательные системные уведомления). */
final readonly class BroadcastResolver implements RecipientResolverInterface
{
    public function __construct(private NotificationAudienceProviderInterface $audience)
    {
    }

    public function key(): ResolverKey
    {
        return ResolverKey::Broadcast;
    }

    public function resolve(NotifiableEvent $event): array
    {
        return $this->audience->allUserUlids();
    }
}
