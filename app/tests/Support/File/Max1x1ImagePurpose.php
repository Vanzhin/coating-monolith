<?php

declare(strict_types=1);

namespace App\Tests\Support\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

/** Назначение с жёстким лимитом габаритов (1x1) — для проверки dimension-валидации. */
final class Max1x1ImagePurpose implements FilePurpose
{
    public function storagePrefix(): string
    {
        return 'test/img';
    }

    public function key(): string
    {
        return 'test.img';
    }

    public function constraints(): FileConstraints
    {
        return new FileConstraints(15 * 1024 * 1024, ['image/png'], 1, 1);
    }
}
