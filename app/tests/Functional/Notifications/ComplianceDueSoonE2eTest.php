<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications;

use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Notifications\Application\EventHandler\NotificationDispatcher;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\DueKind;
use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Repository\Pager;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Пилот end-to-end: админ подписан на compliance.due_soon (inbox) → при событии про сотрудника админ
 * (как надзорный) получает inbox-запись с текстом. Диспетчер зовём напрямую (в тестах async-воркера нет).
 */
final class ComplianceDueSoonE2eTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_subscribed_admin_gets_inbox_due_soon(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $admin = new User(new Email('e2e_'.uniqid('', true).'@example.com'));
        $admin->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($admin, 'isActive'))->setValue($admin, true);
        (new \ReflectionProperty($admin, 'roles'))->setValue($admin, ['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $c->get(SubscriptionRepositoryInterface::class)->save(
            new Subscription(Uuid::v7(), $admin->getUlid(), NotificationType::ComplianceDueSoon, NotificationChannel::Inbox, true),
        );

        ['profileId' => $profileId] = $this->enrollCompliance();
        $event = new ComplianceDueSoon($profileId, 'Иванов И.И.', new ComplianceDueItem('Перчатки', DueKind::Soon, '05.12.2026'));
        $c->get(NotificationDispatcher::class)->__invoke($event); // в тестах зовём диспетчер напрямую

        $rows = $c->get(NotificationRepositoryInterface::class)
            ->findByFilter(new NotificationFilter($admin->getUlid(), null, Pager::fromPage(1, 10)))->items;
        self::assertCount(1, $rows);
        self::assertStringContainsString('Перчатки', $rows[0]->getMessage());
    }
}
