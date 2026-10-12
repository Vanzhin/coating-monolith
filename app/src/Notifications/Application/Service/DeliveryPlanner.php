<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service;

use App\Notifications\Application\Service\Render\MessageRendererRegistry;
use App\Notifications\Application\Service\Resolver\ResolverRegistry;
use App\Notifications\Domain\Event\DeliveryPerspective;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\NotificationDeliveryRequested;
use App\Notifications\Domain\Event\RecipientGroup;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Service\SubjectContextProviderInterface;

/**
 * Раскладка: из набора событий (пачка прохода или одно событие) делает список отправок «получатель × канал».
 * Событие разворачивается в адресатов (резолвер), каждый адресат с перспективой («про меня» — он субъект,
 * «про других» — надзор) копится в группу (получатель, тип, перспектива); по группе — один текст (сводка для
 * надзора, персонально для субъекта) на каждый активный канал. Только чтение; ничего не шлёт и не пишет в БД.
 */
final readonly class DeliveryPlanner
{
    public function __construct(
        private ResolverRegistry $resolvers,
        private ChannelGate $channelGate,
        private MessageRendererRegistry $renderer,
        private SubjectContextProviderInterface $subjects,
    ) {
    }

    /**
     * @return list<NotificationDeliveryRequested>
     */
    public function plan(NotifiableEvent ...$events): array
    {
        /** @var array<string, array{user: string, perspective: DeliveryPerspective, events: list<NotifiableEvent>}> $groups */
        $groups = [];
        foreach ($events as $event) {
            $ownerUlid = $this->ownerOf($event);
            foreach ($this->resolvers->resolve($event) as $userUlid) {
                $perspective = (null === $ownerUlid || $userUlid === $ownerUlid)
                    ? DeliveryPerspective::Personal
                    : DeliveryPerspective::Oversight;
                $key = $userUlid.'|'.$event->notificationType()->value.'|'.$perspective->name;
                $groups[$key]['user'] = $userUlid;
                $groups[$key]['perspective'] = $perspective;
                $groups[$key]['events'][] = $event;
            }
        }

        $deliveries = [];
        foreach ($groups as $group) {
            $recipientGroup = new RecipientGroup($group['user'], $group['perspective'], ...$group['events']);
            $channels = $this->channelGate->activeChannels($group['user'], $recipientGroup->type());
            if ([] === $channels) {
                continue;
            }
            foreach ($this->renderer->messagesFor($recipientGroup) as $message) {
                foreach ($channels as $channel) {
                    $deliveries[] = new NotificationDeliveryRequested($group['user'], $channel, $message);
                }
            }
        }

        return $deliveries;
    }

    private function ownerOf(NotifiableEvent $event): ?string
    {
        if (!$event instanceof SubjectNotification) {
            return null;
        }

        return $this->subjects->userUlidOfProfile($event->subjectProfileId());
    }
}
