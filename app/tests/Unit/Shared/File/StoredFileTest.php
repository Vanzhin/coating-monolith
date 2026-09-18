<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\File;

use App\Shared\Domain\File\StoredFile;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\File\FakePurpose;
use PHPUnit\Framework\TestCase;

final class StoredFileTest extends TestCase
{
    public function test_staged_builds_tmp_key_and_carries_ttl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::staged('uuid-1', 'user-9', 'photo.png', 'image/png', 'png', 2048, $now, $now->modify('+2 hours'));

        self::assertSame(StoredFile::STATUS_STAGED, $file->status());
        self::assertSame('tmp/user-9/uuid-1.png', $file->storageKey());
        self::assertNull($file->purpose());
        self::assertNull($file->ownerId());
        self::assertEquals($now->modify('+2 hours'), $file->expiresAt());
        self::assertSame(2048, $file->size());
    }

    public function test_stored_builds_purpose_key_and_no_ttl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::stored('uuid-2', new FakePurpose(), 'owner-7', 'doc.pdf', 'application/pdf', 'pdf', 4096, $now);

        self::assertSame(StoredFile::STATUS_STORED, $file->status());
        self::assertSame('test/fake/owner-7/uuid-2.pdf', $file->storageKey());
        self::assertSame('test.fake', $file->purpose());
        self::assertSame('owner-7', $file->ownerId());
        self::assertNull($file->expiresAt());
    }

    public function test_promote_moves_staged_to_stored(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::staged('uuid-3', 'user-9', 'photo.png', 'image/png', 'png', 2048, $now, $now->modify('+2 hours'));

        $file->promote(new FakePurpose(), 'owner-7');

        self::assertSame(StoredFile::STATUS_STORED, $file->status());
        self::assertSame('test.fake', $file->purpose());
        self::assertSame('owner-7', $file->ownerId());
        self::assertSame('test/fake/owner-7/uuid-3.png', $file->storageKey());
        self::assertNull($file->expiresAt());
    }

    public function test_double_promote_rejected(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::staged('uuid-4', 'user-9', 'photo.png', 'image/png', 'png', 2048, $now, $now->modify('+2 hours'));
        $file->promote(new FakePurpose(), 'owner-7');

        $this->expectException(AppException::class);
        $file->promote(new FakePurpose(), 'owner-8');
    }
}
