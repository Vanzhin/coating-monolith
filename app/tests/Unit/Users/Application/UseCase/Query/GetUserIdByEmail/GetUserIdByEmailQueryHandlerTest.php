<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Application\UseCase\Query\GetUserIdByEmail;

use App\Users\Application\UseCase\Query\GetUserIdByEmail\GetUserIdByEmailQuery;
use App\Users\Application\UseCase\Query\GetUserIdByEmail\GetUserIdByEmailQueryHandler;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class GetUserIdByEmailQueryHandlerTest extends TestCase
{
    public function test_returns_ulid_when_user_exists(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUlid')->willReturn('owner-ulid');
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('getByEmail')->with('a@example.com')->willReturn($user);

        self::assertSame('owner-ulid', (new GetUserIdByEmailQueryHandler($repo))(new GetUserIdByEmailQuery('a@example.com')));
    }

    public function test_returns_null_when_absent(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('getByEmail')->willReturn(null);

        self::assertNull((new GetUserIdByEmailQueryHandler($repo))(new GetUserIdByEmailQuery('nobody@example.com')));
    }
}
