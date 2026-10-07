<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Infrastructure\Controller;

use App\Notifications\Application\Service\SubscriptionSettingsService;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsControllerTest extends WebTestCase
{
    public function test_settings_page_hides_system_and_saves_configurable(): void
    {
        $client = static::createClient();
        $c = $client->getContainer();
        $admin = new User(new Email('set_'.uniqid('', true).'@example.com'));
        $admin->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($admin, 'isActive'))->setValue($admin, true);
        (new \ReflectionProperty($admin, 'roles'))->setValue($admin, ['ROLE_ADMIN']);
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($admin);
        $em->flush();
        $client->loginUser($admin);

        $html = (string) $client->request('GET', '/cabinet/notifications/settings')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('СИЗ: подходит срок', $html, 'настраиваемый тип показан');
        self::assertStringNotContainsString('Новый пользователь', $html, 'системный тип скрыт');

        $client->request('POST', '/cabinet/notifications/settings', [
            'type' => 'compliance.due_soon',
            'channels' => ['email' => '1'],
        ]);
        self::assertResponseRedirects();
        self::assertTrue($c->get(SubscriptionRepositoryInterface::class)
            ->isEnabled($admin->getUlid(), NotificationType::ComplianceDueSoon, NotificationChannel::Email));
    }

    public function test_regular_employee_can_subscribe_to_own_due_soon(): void
    {
        $client = static::createClient();
        $c = $client->getContainer();
        $user = new User(new Email('emp_'.uniqid('', true).'@example.com'));
        $user->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true); // без ROLE_ADMIN
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $html = (string) $client->request('GET', '/cabinet/notifications/settings')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('СИЗ: подходит срок', $html, 'сотрудник видит тип и может подписаться на свой срок');

        $client->request('POST', '/cabinet/notifications/settings', [
            'type' => 'compliance.due_soon',
            'channels' => ['inbox' => '1'],
        ]);
        self::assertResponseRedirects();
        self::assertTrue($c->get(SubscriptionRepositoryInterface::class)
            ->isEnabled($user->getUlid(), NotificationType::ComplianceDueSoon, NotificationChannel::Inbox));
    }

    public function test_toggle_system_type_is_rejected(): void
    {
        $client = static::createClient();
        $service = $client->getContainer()->get(SubscriptionSettingsService::class);

        $this->expectException(AppException::class);
        $service->toggle('u-x', NotificationType::UserActivated, NotificationChannel::Inbox, false);
    }
}
