<?php

declare(strict_types=1);

namespace App\Shared\Application\File\StageFiles;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\File\StagedFileView;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Генерик-загрузка в tmp-зону. Широкий guard (без знания финального назначения);
 * строгую валидацию по purpose делает FileStorage::promote при привязке к сущности.
 */
final readonly class StageFilesCommandHandler implements CommandHandlerInterface
{
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private FileStorage $storage)
    {
    }

    public function __invoke(StageFilesCommand $command): StageFilesCommandResult
    {
        $views = [];
        foreach ($command->files as $file) {
            $this->guard($file);
            $stored = $this->storage->stage($command->uploaderId, $file);
            $views[] = new StagedFileView($stored->id(), $stored->originalName(), $stored->size(), $stored->mime());
        }

        return new StageFilesCommandResult(...$views);
    }

    private function guard(UploadedFile $file): void
    {
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new AppException('Файл слишком большой (максимум 15 МБ).');
        }
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new AppException('Недопустимый тип файла.');
        }
    }
}
