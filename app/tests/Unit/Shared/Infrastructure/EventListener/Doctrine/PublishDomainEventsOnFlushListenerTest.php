<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener\Doctrine;

use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Event\EventInterface;
use App\Shared\Infrastructure\EventListener\Doctrine\PublishDomainEventsOnFlushListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use PHPUnit\Framework\TestCase;

final class PublishDomainEventsOnFlushListenerTest extends TestCase
{
    public function test_post_flush_deduplicates_identical_events(): void
    {
        $dispatched = [];
        $bus = new class($dispatched) implements EventBusInterface {
            public function __construct(private array &$log)
            {
            }

            public function execute(EventInterface ...$event): void
            {
                foreach ($event as $e) {
                    $this->log[] = $e;
                }
            }
        };

        $listener = new PublishDomainEventsOnFlushListener($bus);

        // Inject 4 events: two identical CoatingSystemMutated('abc') + one unique CoatingSystemMutated('xyz')
        // + one CoatingMutated('abc') — same payload but different class, must NOT be deduped with first group.
        $event1a = new class('abc') implements EventInterface {
            public function __construct(public readonly string $systemId)
            {
            }
        };
        $event1b = new ($event1a::class)('abc'); // same class + same payload
        $event2 = new ($event1a::class)('xyz'); // same class, different payload

        // Use reflection to push pending events, bypassing onFlush Doctrine dependency.
        $ref = new \ReflectionProperty(PublishDomainEventsOnFlushListener::class, 'pendingEvents');
        $ref->setValue($listener, [$event1a, $event1b, $event2]);

        $em = $this->createMock(EntityManagerInterface::class);
        $listener->postFlush(new PostFlushEventArgs($em));

        self::assertCount(2, $dispatched, 'Два дубля должны схлопнуться в одно событие.');
    }

    public function test_post_flush_does_not_deduplicate_events_with_different_payload(): void
    {
        $dispatched = [];
        $bus = new class($dispatched) implements EventBusInterface {
            public function __construct(private array &$log)
            {
            }

            public function execute(EventInterface ...$event): void
            {
                foreach ($event as $e) {
                    $this->log[] = $e;
                }
            }
        };

        $listener = new PublishDomainEventsOnFlushListener($bus);

        $event1 = new class('id-1') implements EventInterface {
            public function __construct(public readonly string $systemId)
            {
            }
        };
        $event2 = new ($event1::class)('id-2');

        $ref = new \ReflectionProperty(PublishDomainEventsOnFlushListener::class, 'pendingEvents');
        $ref->setValue($listener, [$event1, $event2]);

        $em = $this->createMock(EntityManagerInterface::class);
        $listener->postFlush(new PostFlushEventArgs($em));

        self::assertCount(2, $dispatched, 'События с разными payload-ами не должны дедублироваться.');
    }

    public function test_post_flush_skips_dispatch_when_no_events(): void
    {
        $dispatched = [];
        $bus = new class($dispatched) implements EventBusInterface {
            public function __construct(private array &$log)
            {
            }

            public function execute(EventInterface ...$event): void
            {
                $this->log[] = true;
            }
        };

        $listener = new PublishDomainEventsOnFlushListener($bus);

        $em = $this->createMock(EntityManagerInterface::class);
        $listener->postFlush(new PostFlushEventArgs($em));

        self::assertEmpty($dispatched, 'При пустом списке событий eventBus::execute не должен вызываться.');
    }
}
