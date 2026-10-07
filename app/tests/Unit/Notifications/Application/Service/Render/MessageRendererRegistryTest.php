<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application\Service\Render;

use App\Notifications\Application\Service\Render\MessageRendererRegistry;
use App\Notifications\Application\Service\Render\UserActivatedRenderer;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\OwnedNotification;
use App\Notifications\Domain\Event\UserActivatedData;
use App\Notifications\Domain\Type\NotificationType;
use PHPUnit\Framework\TestCase;

final class MessageRendererRegistryTest extends TestCase
{
    public function test_registry_renders_by_type(): void
    {
        $reg = new MessageRendererRegistry([new UserActivatedRenderer()]);
        $e = new class implements NotifiableEvent, OwnedNotification, UserActivatedData {
            public function notificationType(): NotificationType
            {
                return NotificationType::UserActivated;
            }

            public function ownerUlid(): string
            {
                return 'u';
            }

            public function newUserEmail(): string
            {
                return 'new@x.io';
            }
        };
        self::assertStringContainsString('new@x.io', $reg->render($e));
    }
}
