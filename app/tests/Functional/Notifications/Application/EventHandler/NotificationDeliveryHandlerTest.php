<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Application\EventHandler;

use App\Notifications\Application\EventHandler\NotificationDeliveryHandler;
use App\Notifications\Domain\Event\NotificationDeliveryRequested;
use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Shared\Domain\Repository\Pager;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationDeliveryHandlerTest extends KernelTestCase
{
    public function test_inbox_delivery_creates_row(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User(new Email('deliv_'.uniqid('', true).'@example.com'));
        $user->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        $em->persist($user);
        $em->flush();
        $ulid = $user->getUlid();

        $c->get(NotificationDeliveryHandler::class)->__invoke(
            new NotificationDeliveryRequested($ulid, NotificationChannel::Inbox, 'Готовый текст'),
        );

        $rows = $c->get(NotificationRepositoryInterface::class)
            ->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(1, $rows);
        self::assertSame('Готовый текст', $rows[0]->getMessage());
    }

    public function test_inbox_delivery_to_nonexistent_user_is_skipped(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $ulid = 'u-'.uniqid('', true);

        $c->get(NotificationDeliveryHandler::class)->__invoke(
            new NotificationDeliveryRequested($ulid, NotificationChannel::Inbox, 'Текст'),
        );

        $rows = $c->get(NotificationRepositoryInterface::class)
            ->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(0, $rows);
    }
}
