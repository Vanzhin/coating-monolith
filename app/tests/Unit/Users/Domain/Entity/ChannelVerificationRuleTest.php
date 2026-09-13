<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Domain\Entity;

use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ChannelVerificationRuleTest extends TestCase
{
    public function test_requires_verification_by_type(): void
    {
        self::assertTrue(ChannelType::EMAIL->requiresVerification());
        self::assertTrue(ChannelType::TELEGRAM->requiresVerification());
        self::assertFalse(ChannelType::WEB_PUSH->requiresVerification());
    }

    public function test_web_push_channel_is_verified_on_creation(): void
    {
        $channel = new Channel(Uuid::v7(), ChannelType::WEB_PUSH, '{"endpoint":"x"}', $this->user());

        self::assertTrue($channel->isVerified());
        self::assertNotNull($channel->getVerifiedAt());
    }

    public function test_email_channel_is_not_verified_on_creation(): void
    {
        $channel = new Channel(Uuid::v7(), ChannelType::EMAIL, 'user@example.com', $this->user());

        self::assertFalse($channel->isVerified());
    }

    private function user(): User
    {
        return new User(new Email('owner@example.com'));
    }
}
