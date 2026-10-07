<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain\Type;

use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationKind;
use App\Notifications\Domain\Type\NotificationType;
use App\Notifications\Domain\Type\ResolverKey;
use App\Users\Domain\Entity\ChannelType;
use PHPUnit\Framework\TestCase;

final class NotificationTypeTest extends TestCase
{
    public function test_configurable_type_metadata(): void
    {
        $t = NotificationType::ComplianceDueSoon;
        self::assertSame('compliance.due_soon', $t->value);
        self::assertSame(NotificationKind::Configurable, $t->kind());
        self::assertFalse($t->kind()->isSystem());
        self::assertSame(ResolverKey::SubjectSupervisors, $t->resolver());
        self::assertContains(NotificationChannel::Inbox, $t->channels());
        self::assertContains(NotificationChannel::Email, $t->channels());
        self::assertContains($t, NotificationType::configurable());
    }

    public function test_system_type_is_mandatory_and_hidden(): void
    {
        $t = NotificationType::UserActivated;
        self::assertTrue($t->kind()->isSystem());
        self::assertNotContains($t, NotificationType::configurable(), 'системные не в списке настраиваемых');
    }

    public function test_channel_maps_to_users_channel_type(): void
    {
        self::assertSame(ChannelType::WEB_PUSH, NotificationChannel::WebPush->toUsersChannelType());
        self::assertSame(ChannelType::EMAIL, NotificationChannel::Email->toUsersChannelType());
        self::assertNull(NotificationChannel::Inbox->toUsersChannelType());
    }
}
