<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain\Event;

use App\Notifications\Domain\Event\DeliveryPerspective;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\RecipientGroup;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RecipientGroupTest extends TestCase
{
    public function test_empty_throws(): void
    {
        $this->expectException(AppException::class);
        new RecipientGroup('u-1', DeliveryPerspective::Personal);
    }

    public function test_mixed_types_throws(): void
    {
        $this->expectException(AppException::class);
        new RecipientGroup('u-1', DeliveryPerspective::Oversight,
            $this->event(NotificationType::ComplianceDueSoon),
            $this->event(NotificationType::UserActivated),
        );
    }

    public function test_type_single_perspective(): void
    {
        $one = new RecipientGroup('u-1', DeliveryPerspective::Personal, $this->event(NotificationType::ComplianceDueSoon));
        self::assertSame(NotificationType::ComplianceDueSoon, $one->type());
        self::assertCount(1, $one->events());
        self::assertSame(DeliveryPerspective::Personal, $one->perspective);
        self::assertSame('u-1', $one->userUlid);

        $many = new RecipientGroup('u-2', DeliveryPerspective::Oversight,
            $this->event(NotificationType::ComplianceDueSoon),
            $this->event(NotificationType::ComplianceDueSoon),
        );
        self::assertCount(2, $many->events());
    }

    private function event(NotificationType $type): NotifiableEvent
    {
        return new class($type) implements NotifiableEvent {
            public function __construct(private NotificationType $type)
            {
            }

            public function notificationType(): NotificationType
            {
                return $this->type;
            }
        };
    }
}
