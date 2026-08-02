<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Doctrine;

use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Event\EventInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PublishDomainEventsOnFlushListener
{
    /** @var list<EventInterface> */
    private array $pendingEvents = [];

    public function __construct(private readonly EventBusInterface $eventBus)
    {
    }

    public function onFlush(OnFlushEventArgs $eventArgs): void
    {
        $unitOfWork = $eventArgs->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->collectDomainEvents($entity);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->collectDomainEvents($entity);
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $this->collectDomainEvents($entity);
        }

        foreach ($unitOfWork->getScheduledCollectionDeletions() as $collection) {
            foreach ($collection as $entity) {
                $this->collectDomainEvents($entity);
            }
        }

        foreach ($unitOfWork->getScheduledCollectionUpdates() as $collection) {
            foreach ($collection as $entity) {
                $this->collectDomainEvents($entity);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $eventArgs): void
    {
        if ([] === $this->pendingEvents) {
            return;
        }

        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        $this->eventBus->execute(...$events);
    }

    private function collectDomainEvents(object $entity): void
    {
        if ($entity instanceof Aggregate && !$entity->eventsEmpty()) {
            foreach ($entity->pullEvents() as $event) {
                $this->pendingEvents[] = $event;
            }
        }
    }
}
