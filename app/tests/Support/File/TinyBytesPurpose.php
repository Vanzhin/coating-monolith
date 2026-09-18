<?php

declare(strict_types=1);

namespace App\Tests\Support\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

/** Назначение с лимитом размера в 1 байт — для проверки отбоя по maxBytes. */
final class TinyBytesPurpose implements FilePurpose
{
    public function storagePrefix(): string
    {
        return 'test/tiny';
    }

    public function key(): string
    {
        return 'test.tiny';
    }

    public function constraints(): FileConstraints
    {
        return new FileConstraints(1, ['application/pdf', 'image/png']);
    }
}
