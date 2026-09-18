<?php

declare(strict_types=1);

namespace App\Shared\Application\File;

final readonly class StagedFileView
{
    public function __construct(
        public string $uuid,
        public string $name,
        public int $size,
        public string $mime,
    ) {
    }
}
