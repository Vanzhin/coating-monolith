<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application\Service\Render;

use App\Notifications\Application\Service\Render\MessageRendererRegistry;
use App\Notifications\Domain\Event\DeliveryPerspective;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\RecipientGroup;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Service\SummaryRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use PHPUnit\Framework\TestCase;

final class MessageRendererRegistryTest extends TestCase
{
    public function test_personal_renders_each_event(): void
    {
        $registry = new MessageRendererRegistry([$this->plain()]);
        $group = new RecipientGroup('u-1', DeliveryPerspective::Personal, $this->event());

        self::assertSame(['one'], $registry->messagesFor($group));
    }

    public function test_oversight_with_summary_renders_one(): void
    {
        $registry = new MessageRendererRegistry([$this->summarizing()]);
        $group = new RecipientGroup('u-1', DeliveryPerspective::Oversight, $this->event(), $this->event());

        self::assertSame(['summary'], $registry->messagesFor($group));
    }

    public function test_oversight_without_summary_renders_each(): void
    {
        $registry = new MessageRendererRegistry([$this->plain()]);
        $group = new RecipientGroup('u-1', DeliveryPerspective::Oversight, $this->event(), $this->event());

        self::assertSame(['one', 'one'], $registry->messagesFor($group));
    }

    private function event(): NotifiableEvent
    {
        return new class implements NotifiableEvent {
            public function notificationType(): NotificationType
            {
                return NotificationType::ComplianceDueSoon;
            }
        };
    }

    private function plain(): MessageRendererInterface
    {
        return new class implements MessageRendererInterface {
            public function type(): NotificationType
            {
                return NotificationType::ComplianceDueSoon;
            }

            public function render(NotifiableEvent $event): string
            {
                return 'one';
            }
        };
    }

    private function summarizing(): MessageRendererInterface
    {
        return new class implements MessageRendererInterface, SummaryRendererInterface {
            public function type(): NotificationType
            {
                return NotificationType::ComplianceDueSoon;
            }

            public function render(NotifiableEvent $event): string
            {
                return 'one';
            }

            public function renderSummary(NotifiableEvent ...$events): string
            {
                return 'summary';
            }
        };
    }
}
