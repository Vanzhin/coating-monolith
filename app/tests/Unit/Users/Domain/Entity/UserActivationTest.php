<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Domain\Entity;

use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Event\UserActivatedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Активация пользователя (makeActiveInternally) должна поднимать UserActivatedEvent ровно один раз —
 * на переходе false→true, и только если есть подтверждённый канал.
 */
final class UserActivationTest extends TestCase
{
    public function test_activation_raises_event_on_transition(): void
    {
        $user = $this->userWithVerifiedChannel();
        $user->pullEvents(); // сбрасываем UserCreatedEvent от конструктора

        $user->makeActiveInternally();

        self::assertTrue($user->isActive());
        $events = $user->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(UserActivatedEvent::class, $events[0]);
        self::assertSame($user->getUlid(), $events[0]->userId);
    }

    public function test_no_event_without_verified_channel(): void
    {
        $user = new User(new Email('nochan@example.com'));
        $user->pullEvents();

        $user->makeActiveInternally();

        self::assertFalse($user->isActive());
        self::assertCount(0, $user->pullEvents());
    }

    public function test_no_event_with_only_unverified_channel(): void
    {
        $user = new User(new Email('unverified@example.com'));
        // EMAIL требует OTP-верификации → канал неподтверждён → активации нет.
        $user->addChannel(new Channel(Uuid::v7(), ChannelType::EMAIL, 'x@example.com', $user));
        $user->pullEvents();

        $user->makeActiveInternally();

        self::assertFalse($user->isActive());
        self::assertCount(0, $user->pullEvents());
    }

    public function test_no_duplicate_event_when_already_active(): void
    {
        $user = $this->userWithVerifiedChannel();
        $user->makeActiveInternally();
        $user->pullEvents(); // очищаем всё после первой активации

        $user->makeActiveInternally();

        self::assertCount(0, $user->pullEvents(), 'повторный вызов не должен слать событие снова');
    }

    private function userWithVerifiedChannel(): User
    {
        $user = new User(new Email('owner@example.com'));
        // WEB_PUSH рождается подтверждённым → пользователь может стать активным.
        $user->addChannel(new Channel(Uuid::v7(), ChannelType::WEB_PUSH, '{"endpoint":"x"}', $user));

        return $user;
    }
}
