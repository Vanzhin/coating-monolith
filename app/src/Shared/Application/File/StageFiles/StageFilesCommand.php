<?php

declare(strict_types=1);

namespace App\Shared\Application\File\StageFiles;

use App\Shared\Application\Command\CommandInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class StageFilesCommand implements CommandInterface
{
    /**
     * @param list<UploadedFile> $files
     */
    public function __construct(
        public string $uploaderId,
        public array $files,
    ) {
    }
}
