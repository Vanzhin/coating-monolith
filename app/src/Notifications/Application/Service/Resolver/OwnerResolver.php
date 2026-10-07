<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\OwnedNotification;
use App\Notifications\Domain\Service\RecipientResolverInterface;
use App\Notifications\Domain\Type\ResolverKey;
use App\Shared\Infrastructure\Exception\AppException;

/** Адресат — владелец события (OwnedNotification). */
final readonly class OwnerResolver implements RecipientResolverInterface
{
    public function key(): ResolverKey
    {
        return ResolverKey::Owner;
    }

    public function resolve(NotifiableEvent $event): array
    {
        if (!$event instanceof OwnedNotification) {
            throw new AppException(sprintf('Событие %s требует OwnedNotification для резолвера Owner.', $event->notificationType()->value));
        }

        return [$event->ownerUlid()];
    }
}
