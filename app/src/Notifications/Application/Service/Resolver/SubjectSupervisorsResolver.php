<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Service\NotificationAudienceProviderInterface;
use App\Notifications\Domain\Service\RecipientResolverInterface;
use App\Notifications\Domain\Service\SubjectContextProviderInterface;
use App\Notifications\Domain\Type\ResolverKey;
use App\Shared\Infrastructure\Exception\AppException;

/** Адресаты — сам сотрудник-субъект + его надзорные (начальник отдела, администраторы). */
final readonly class SubjectSupervisorsResolver implements RecipientResolverInterface
{
    public function __construct(
        private SubjectContextProviderInterface $subject,
        private NotificationAudienceProviderInterface $audience,
    ) {
    }

    public function key(): ResolverKey
    {
        return ResolverKey::SubjectSupervisors;
    }

    public function resolve(NotifiableEvent $event): array
    {
        if (!$event instanceof SubjectNotification) {
            throw new AppException(sprintf('Событие %s требует SubjectNotification.', $event->notificationType()->value));
        }
        $profileId = $event->subjectProfileId();
        $recipients = [];
        if (null !== $self = $this->subject->userUlidOfProfile($profileId)) {
            $recipients[] = $self;
        }
        if (null !== $head = $this->subject->departmentHeadUlidOfProfile($profileId)) {
            $recipients[] = $head;
        }
        foreach ($this->audience->adminUlids() as $admin) {
            $recipients[] = $admin;
        }

        return array_values(array_unique($recipients));
    }
}
