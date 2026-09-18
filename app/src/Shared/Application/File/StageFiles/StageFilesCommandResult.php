<?php

declare(strict_types=1);

namespace App\Shared\Application\File\StageFiles;

use App\Shared\Application\File\StagedFileView;

final readonly class StageFilesCommandResult
{
    /** @var list<StagedFileView> */
    public array $files;

    public function __construct(StagedFileView ...$files)
    {
        $this->files = $files;
    }
}
