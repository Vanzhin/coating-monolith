<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverLicense;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DriverLicenseTest extends TestCase
{
    #[DataProvider('validLicenses')]
    public function test_normalizes_and_formats_license(string $input, string $value, string $formatted): void
    {
        $license = new DriverLicense($input);

        self::assertSame($value, $license->value());
        self::assertSame($formatted, $license->formatted());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validLicenses(): iterable
    {
        yield 'numeric' => ['99 12 123456', '9912123456', '99 12 123456'];
        yield 'numeric without separators' => ['9912123456', '9912123456', '99 12 123456'];
        yield 'cyrillic lowercase and hyphens' => [' 77-аа-123456 ', '77AA123456', '77 AA 123456'];
        yield 'mixed alphabets' => ['77аB123456', '77AB123456', '77 AB 123456'];
        yield 'cyrillic v and e' => ['77ве123456', '77BE123456', '77 BE 123456'];
        yield 'cyrillic k and m' => ['77км123456', '77KM123456', '77 KM 123456'];
        yield 'cyrillic n and o' => ['77но123456', '77HO123456', '77 HO 123456'];
        yield 'cyrillic r and s' => ['77рс123456', '77PC123456', '77 PC 123456'];
        yield 'cyrillic t and u' => ['77ту123456', '77TY123456', '77 TY 123456'];
        yield 'cyrillic x' => ['77хх123456', '77XX123456', '77 XX 123456'];
    }

    #[DataProvider('invalidLicenses')]
    public function test_rejects_invalid_license(string $input): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('водительского удостоверения');
        new DriverLicense($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLicenses(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['991212345'];
        yield 'long' => ['99121234567'];
        yield 'invalid latin letter' => ['77AZ123456'];
        yield 'invalid cyrillic letter' => ['77АБ123456'];
        yield 'letter in numeric part' => ['991212345A'];
        yield 'invalid series' => ['7A12123456'];
        yield 'punctuation' => ['99/12/123456'];
        yield 'non-ascii digits' => ['９９12123456'];
        yield 'invalid utf8' => ["\xff"];
    }
}
