<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SubscriptionRepositoryTest extends KernelTestCase
{
    public function test_save_and_query_enabled(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(SubscriptionRepositoryInterface::class);
        $ulid = 'u-'.uniqid('', true);

        $repo->save(new Subscription(Uuid::v7(), $ulid, NotificationType::ComplianceDueSoon, NotificationChannel::Email, true));

        self::assertTrue($repo->isEnabled($ulid, NotificationType::ComplianceDueSoon, NotificationChannel::Email));
        self::assertFalse($repo->isEnabled($ulid, NotificationType::ComplianceDueSoon, NotificationChannel::WebPush));
        self::assertCount(1, $repo->findForUser($ulid));
    }
}
