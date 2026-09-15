<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Service\UnreadNotificationCounterInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\WebPushNotifier;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class WebPushNotifierTest extends TestCase
{
    private WebPushNotifier $notifier;

    protected function setUp(): void
    {
        $this->notifier = new WebPushNotifier(
            'pub',
            'priv',
            'mailto:x@example.com',
            $this->createMock(ChannelRepositoryInterface::class),
            $this->createMock(UnreadNotificationCounterInterface::class),
            new NullLogger(),
        );
    }

    public function test_supports_only_web_push(): void
    {
        self::assertTrue($this->notifier->isSupportedChannel($this->channel(ChannelType::WEB_PUSH)));
        self::assertFalse($this->notifier->isSupportedChannel($this->channel(ChannelType::EMAIL)));
    }

    public function test_notify_rejects_unsupported_channel(): void
    {
        $this->expectException(AppException::class);
        $this->notifier->notify($this->channel(ChannelType::EMAIL), 'привет');
    }

    public function test_send_verification_code_is_noop(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notifier->sendVerificationCode($this->channel(ChannelType::WEB_PUSH), '123', 300);
    }

    private function channel(ChannelType $type): Channel
    {
        return new Channel(Uuid::v7(), $type, 'email' === $type->value ? 'a@b.co' : '{"endpoint":"x"}', new User(new Email('o@example.com')));
    }
}
