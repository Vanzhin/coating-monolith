<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Console;

use App\Shared\Domain\Security\SystemUser;
use App\Shared\Infrastructure\Console\ConsoleAuthenticationSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class ConsoleAuthenticationSubscriberTest extends TestCase
{
    public function test_sets_system_principal_when_no_token(): void
    {
        $storage = new TokenStorage();
        (new ConsoleAuthenticationSubscriber($storage))->authenticateSystem();

        self::assertInstanceOf(SystemUser::class, $storage->getToken()?->getUser());
    }

    public function test_keeps_existing_token(): void
    {
        $storage = new TokenStorage();
        $existing = new NullToken();
        $storage->setToken($existing);

        (new ConsoleAuthenticationSubscriber($storage))->authenticateSystem();

        self::assertSame($existing, $storage->getToken(), 'реальный токен не перетираем');
    }

    public function test_rearms_on_both_console_command_and_each_worker_message(): void
    {
        $events = ConsoleAuthenticationSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ConsoleEvents::COMMAND, $events);
        self::assertArrayHasKey(WorkerMessageReceivedEvent::class, $events, 'воркер теряет токен на reset → взводим на каждое сообщение');
    }
}
