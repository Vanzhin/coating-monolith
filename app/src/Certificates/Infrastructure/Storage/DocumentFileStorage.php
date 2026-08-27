<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Storage;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Хранилище PDF-сканов документов поверх flysystem-адаптера document_scans.
 * Домен оперирует только строкой-ключом (путь), физику держит здесь.
 */
final readonly class DocumentFileStorage
{
    public function __construct(private FilesystemOperator $filesystem)
    {
    }

    /**
     * Сохраняет загруженный PDF под уникальным именем, возвращает ключ для хранения в документе.
     */
    public function store(UploadedFile $file): string
    {
        $key = Uuid::v7()->toRfc4122().'.pdf';
        $this->filesystem->write($key, $file->getContent());

        return $key;
    }

    public function delete(string $path): void
    {
        if ($this->filesystem->fileExists($path)) {
            $this->filesystem->delete($path);
        }
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        return $this->filesystem->readStream($path);
    }
}
