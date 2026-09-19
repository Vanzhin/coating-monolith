<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Domain\File;

use App\Certificates\Domain\File\CertificatePurpose;
use PHPUnit\Framework\TestCase;

final class CertificatePurposeTest extends TestCase
{
    public function test_scan_exposes_prefix_key_and_constraints(): void
    {
        $purpose = CertificatePurpose::Scan;

        self::assertSame('certificates/scan', $purpose->storagePrefix());
        self::assertSame('certificate.scan', $purpose->key());
        self::assertSame(['application/pdf'], $purpose->constraints()->mimeTypes());
        self::assertSame(20 * 1024 * 1024, $purpose->constraints()->maxBytes());
    }
}
