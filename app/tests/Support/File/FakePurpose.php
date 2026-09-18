<?php

declare(strict_types=1);

namespace App\Tests\Support\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

final class FakePurpose implements FilePurpose
{
    public function storagePrefix(): string
    {
        return 'test/fake';
    }

    public function key(): string
    {
        return 'test.fake';
    }

    public function constraints(): FileConstraints
    {
        return new FileConstraints(15 * 1024 * 1024, ['image/png', 'application/pdf']);
    }
}
