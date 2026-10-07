<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Application\Service;

use App\Notifications\Application\Service\ChannelGate;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** ChannelGate — dangling до диспетчера, собираем напрямую с репозиторием из контейнера. */
final class ChannelGateTest extends KernelTestCase
{
    public function test_system_type_all_channels_regardless_of_subscription(): void
    {
        self::bootKernel();
        $gate = new ChannelGate(self::getContainer()->get(SubscriptionRepositoryInterface::class));
        $channels = $gate->activeChannels('u-any', NotificationType::UserActivated); // системный
        self::assertEqualsCanonicalizing(NotificationType::UserActivated->channels(), $channels);
    }

    public function test_configurable_type_only_subscribed_channels(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(SubscriptionRepositoryInterface::class);
        $gate = new ChannelGate($repo);
        $ulid = 'u-'.uniqid('', true);
        $repo->save(new Subscription(Uuid::v7(), $ulid, NotificationType::ComplianceDueSoon, NotificationChannel::WebPush, true));

        $channels = $gate->activeChannels($ulid, NotificationType::ComplianceDueSoon);
        self::assertSame([NotificationChannel::WebPush], $channels, 'только подписанный push, без inbox/email');
    }

    public function test_configurable_type_no_subscription_empty(): void
    {
        self::bootKernel();
        $gate = new ChannelGate(self::getContainer()->get(SubscriptionRepositoryInterface::class));
        self::assertSame([], $gate->activeChannels('u-nobody', NotificationType::ComplianceDueSoon));
    }
}
