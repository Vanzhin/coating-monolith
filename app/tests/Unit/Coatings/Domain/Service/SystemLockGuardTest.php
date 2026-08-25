<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Service;

use App\Coatings\Domain\Service\SystemCertificatesGateway;
use App\Coatings\Domain\Service\SystemLockGuard;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class SystemLockGuardTest extends TestCase
{
    public function test_throws_when_system_has_certificates(): void
    {
        $guard = new SystemLockGuard($this->gateway(true));

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/сертификат/');

        $guard->assertModifiable('any-system-id');
    }

    public function test_ok_when_no_certificates(): void
    {
        $guard = new SystemLockGuard($this->gateway(false));

        $guard->assertModifiable('any-system-id');

        $this->expectNotToPerformAssertions();
    }

    private function gateway(bool $hasCertificates): SystemCertificatesGateway
    {
        return new class($hasCertificates) implements SystemCertificatesGateway {
            public function __construct(private readonly bool $has)
            {
            }

            public function hasCertificates(string $systemId): bool
            {
                return $this->has;
            }

            public function countBySystemIds(StringCollection $systemIds): array
            {
                return [];
            }

            public function listBySystem(string $systemId): array
            {
                return [];
            }
        };
    }
}
