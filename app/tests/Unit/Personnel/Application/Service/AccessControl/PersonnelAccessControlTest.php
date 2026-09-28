<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Application\Service\AccessControl;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Shared\Application\Security\AccessGuard;
use App\Shared\Application\Security\AuthChecker;
use App\Shared\Domain\Security\Role;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class PersonnelAccessControlTest extends TestCase
{
    public function test_system_principal_can_manage(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')
            ->willReturnCallback(static fn (string $attribute): bool => Role::ROLE_SYSTEM === $attribute);

        $access = new PersonnelAccessControl(new AccessGuard(new AuthChecker($authorizationChecker)));

        self::assertTrue($access->canManage());
    }

    public function test_unauthenticated_actor_cannot_manage(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(false);

        $access = new PersonnelAccessControl(new AccessGuard(new AuthChecker($authorizationChecker)));

        self::assertFalse($access->canManage());
    }
}
