<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DriverDetailsTest extends TestCase
{
    public function test_keeps_driver_and_license_details(): void
    {
        $issuedAt = new \DateTimeImmutable('2020-01-01');
        $details = new DriverDetails(' Иванов Иван ', ' 00 00 000000 ', $issuedAt, ' 000-000-000 00 ');

        self::assertSame('Иванов Иван', $details->fullName);
        self::assertSame('00 00 000000', $details->licenseNumber->formatted());
        self::assertSame($issuedAt, $details->licenseIssuedAt);
        self::assertSame('00000000000', $details->snils->value());
        self::assertSame('000-000-000 00', $details->snils->formatted());
    }

    #[DataProvider('missingDetails')]
    public function test_rejects_missing_required_text(string $name, string $license, string $snils): void
    {
        $this->expectException(AppException::class);
        new DriverDetails($name, $license, new \DateTimeImmutable('2020-01-01'), $snils);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function missingDetails(): iterable
    {
        yield 'name' => [' ', '00 00 000000', '000-000-000 00'];
        yield 'license' => ['Иванов', '', '000-000-000 00'];
        yield 'snils' => ['Иванов', '00 00 000000', ' '];
        yield 'unicode whitespace' => ["\u{00A0}", '00 00 000000', '000-000-000 00'];
    }
}
