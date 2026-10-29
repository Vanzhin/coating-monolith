<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications;

use App\Notifications\Application\EventHandler\NotificationDispatcher;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Event\InquiryProcessedNotification;
use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Repository\Pager;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** «Запрос обработан» (настраиваемый, Owner): подписанный владелец получает inbox, неподписанный — нет. */
final class InquiryProcessedE2eTest extends KernelTestCase
{
    public function test_subscribed_owner_gets_inbox(): void
    {
        [$c, $ulid] = $this->bootWithUser();
        $c->get(SubscriptionRepositoryInterface::class)->save(
            new Subscription(Uuid::v7(), $ulid, NotificationType::InquiryProcessed, NotificationChannel::Inbox, true),
        );

        $c->get(NotificationDispatcher::class)->__invoke(new InquiryProcessedNotification($ulid, 'inq-1', false));

        $rows = $c->get(NotificationRepositoryInterface::class)->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(1, $rows);
    }

    public function test_unsubscribed_owner_gets_nothing(): void
    {
        [$c, $ulid] = $this->bootWithUser();

        $c->get(NotificationDispatcher::class)->__invoke(new InquiryProcessedNotification($ulid, 'inq-2', true, 'таймаут'));

        $rows = $c->get(NotificationRepositoryInterface::class)->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(0, $rows);
    }

    /** @return array{0: \Psr\Container\ContainerInterface, 1: string} */
    private function bootWithUser(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User(new Email('inqproc_'.uniqid('', true).'@example.com'));
        $user->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        $em->persist($user);
        $em->flush();

        return [$c, $user->getUlid()];
    }
}
