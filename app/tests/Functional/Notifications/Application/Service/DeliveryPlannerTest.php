<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Application\Service;

use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Notifications\Application\Service\DeliveryPlanner;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\DueKind;
use App\Notifications\Domain\Event\NotificationDeliveryRequested;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DeliveryPlannerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem(); // enrollCompliance требует canManage
    }

    public function test_admin_gets_one_summary_for_many_employees(): void
    {
        $admin = $this->createUser(admin: true);
        $u1 = $this->createUser();
        $u2 = $this->createUser();
        foreach ([$admin, $u1, $u2] as $u) {
            $this->subscribeInbox($u->getUlid());
        }
        ['profileId' => $p1] = $this->enrollCompliance('Перчатки', $u1->getUlid());
        ['profileId' => $p2] = $this->enrollCompliance('Каска', $u2->getUlid());

        $deliveries = $this->planner()->plan(
            $this->due($p1, 'Иванов', DueKind::Soon),
            $this->due($p2, 'Петров', DueKind::Overdue),
        );

        $adminD = $this->forUser($deliveries, $admin->getUlid());
        self::assertCount(1, $adminD, 'админу одно сводное, а не по одному на сотрудника');
        self::assertStringContainsString('Сотрудников: 2', $adminD[0]->message);

        $u1D = $this->forUser($deliveries, $u1->getUlid());
        self::assertCount(1, $u1D);
        self::assertStringContainsString('Иванов', $u1D[0]->message);
    }

    public function test_admin_who_is_employee_gets_personal_and_summary(): void
    {
        $admin = $this->createUser(admin: true);
        $u1 = $this->createUser();
        $this->subscribeInbox($admin->getUlid());
        $this->subscribeInbox($u1->getUlid());
        ['profileId' => $pa] = $this->enrollCompliance('Перчатки', $admin->getUlid());
        ['profileId' => $p1] = $this->enrollCompliance('Каска', $u1->getUlid());

        $deliveries = $this->planner()->plan(
            $this->due($pa, 'Админов А.А.', DueKind::Overdue),
            $this->due($p1, 'Иванов', DueKind::Soon),
        );

        $adminD = $this->forUser($deliveries, $admin->getUlid());
        self::assertCount(2, $adminD, 'админ-сотрудник: персональное про себя + сводка про других');
        $joined = implode("\n", array_map(static fn (NotificationDeliveryRequested $d): string => $d->message, $adminD));
        self::assertStringContainsString('Админов А.А.', $joined);
        self::assertStringContainsString('Сотрудников: 1', $joined);
    }

    public function test_unsubscribed_recipient_gets_nothing(): void
    {
        $u1 = $this->createUser(); // без подписки, без админов в системе
        ['profileId' => $p1] = $this->enrollCompliance('Перчатки', $u1->getUlid());

        $deliveries = $this->planner()->plan($this->due($p1, 'Иванов', DueKind::Soon));

        self::assertCount(0, $this->forUser($deliveries, $u1->getUlid()));
    }

    private function planner(): DeliveryPlanner
    {
        return self::getContainer()->get(DeliveryPlanner::class);
    }

    private function createUser(bool $admin = false): User
    {
        $c = self::getContainer();
        $user = new User(new Email('pl_'.uniqid('', true).'@example.com'));
        $user->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        if ($admin) {
            (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        }
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function subscribeInbox(string $ulid): void
    {
        self::getContainer()->get(SubscriptionRepositoryInterface::class)->save(
            new Subscription(Uuid::v7(), $ulid, NotificationType::ComplianceDueSoon, NotificationChannel::Inbox, true),
        );
    }

    private function due(string $profileId, string $fio, DueKind ...$kinds): ComplianceDueSoon
    {
        $items = [];
        foreach ($kinds as $i => $kind) {
            $items[] = new ComplianceDueItem('поз'.$i, $kind, '01.12.2026');
        }

        return new ComplianceDueSoon($profileId, $fio, ...$items);
    }

    /**
     * @param list<NotificationDeliveryRequested> $deliveries
     *
     * @return list<NotificationDeliveryRequested>
     */
    private function forUser(array $deliveries, string $ulid): array
    {
        return array_values(array_filter($deliveries, static fn (NotificationDeliveryRequested $d): bool => $d->userUlid === $ulid));
    }
}
