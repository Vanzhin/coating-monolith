<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class FlysystemFileStorage implements FileStorage
{
    private const STAGE_TTL = '+2 hours';

    public function __construct(
        private FilesystemOperator $filesystem,
        private StoredFileRepositoryInterface $repository,
    ) {
    }

    public function store(FilePurpose $purpose, string $ownerId, UploadedFile $file): StoredFile
    {
        $this->validate($file, $purpose->constraints());
        $stored = StoredFile::stored(
            Uuid::v7()->toRfc4122(),
            $purpose,
            $ownerId,
            $file->getClientOriginalName(),
            $this->mime($file),
            $this->extension($file),
            (int) $file->getSize(),
            new \DateTimeImmutable(),
        );
        $this->filesystem->write($stored->storageKey(), $file->getContent());
        $this->repository->add($stored);

        return $stored;
    }

    public function stage(string $uploaderId, UploadedFile $file): StoredFile
    {
        $now = new \DateTimeImmutable();
        $staged = StoredFile::staged(
            Uuid::v7()->toRfc4122(),
            $uploaderId,
            $file->getClientOriginalName(),
            $this->mime($file),
            $this->extension($file),
            (int) $file->getSize(),
            $now,
            $now->modify(self::STAGE_TTL),
        );
        $this->filesystem->write($staged->storageKey(), $file->getContent());
        $this->repository->add($staged);

        return $staged;
    }

    public function promote(string $uuid, FilePurpose $purpose, string $ownerId): StoredFile
    {
        $file = $this->require($uuid);
        $this->validateMeta($file->mime(), $file->size(), $purpose->constraints());

        $oldKey = $file->storageKey();
        $file->promote($purpose, $ownerId);
        $this->filesystem->move($oldKey, $file->storageKey());
        $this->repository->add($file);

        return $file;
    }

    public function get(string $uuid): ?StoredFile
    {
        return $this->repository->get($uuid);
    }

    public function byOwner(FilePurpose $purpose, string $ownerId): array
    {
        return $this->repository->byOwner($purpose->key(), $ownerId);
    }

    public function readStream(string $uuid)
    {
        return $this->filesystem->readStream($this->require($uuid)->storageKey());
    }

    public function toLocalTempFile(string $uuid): string
    {
        $stream = $this->readStream($uuid);
        $path = tempnam(sys_get_temp_dir(), 'file_');
        file_put_contents($path, $stream);

        return $path;
    }

    public function remove(string $uuid): void
    {
        $file = $this->repository->get($uuid);
        if (null === $file) {
            return;
        }
        $this->deleteRecord($file);
    }

    public function removeByOwner(FilePurpose $purpose, string $ownerId): void
    {
        foreach ($this->repository->byOwner($purpose->key(), $ownerId) as $file) {
            $this->deleteRecord($file);
        }
    }

    public function purgeExpired(): int
    {
        $expired = $this->repository->expiredStaged(new \DateTimeImmutable());
        foreach ($expired as $file) {
            $this->deleteRecord($file);
        }

        return count($expired);
    }

    private function require(string $uuid): StoredFile
    {
        return $this->repository->get($uuid)
            ?? throw new AppException('Файл не найден.', Response::HTTP_NOT_FOUND);
    }

    private function deleteRecord(StoredFile $file): void
    {
        if ($this->filesystem->fileExists($file->storageKey())) {
            $this->filesystem->delete($file->storageKey());
        }
        $this->repository->remove($file);
    }

    private function validate(UploadedFile $file, FileConstraints $constraints): void
    {
        $this->validateMeta($this->mime($file), (int) $file->getSize(), $constraints);

        if (null === $constraints->maxWidth() && null === $constraints->maxHeight()) {
            return;
        }
        $dimensions = @getimagesize($file->getPathname());
        if (false === $dimensions) {
            throw new AppException('Не удалось прочитать изображение.');
        }
        [$width, $height] = $dimensions;
        if (null !== $constraints->maxWidth() && $width > $constraints->maxWidth()) {
            throw new AppException('Ширина изображения превышает допустимую.');
        }
        if (null !== $constraints->maxHeight() && $height > $constraints->maxHeight()) {
            throw new AppException('Высота изображения превышает допустимую.');
        }
    }

    private function validateMeta(string $mime, int $size, FileConstraints $constraints): void
    {
        if ($size > $constraints->maxBytes()) {
            throw new AppException('Файл превышает допустимый размер.');
        }
        if (!in_array($mime, $constraints->mimeTypes(), true)) {
            throw new AppException('Недопустимый тип файла.');
        }
    }

    private function mime(UploadedFile $file): string
    {
        return $file->getMimeType() ?? 'application/octet-stream';
    }

    private function extension(UploadedFile $file): string
    {
        return $file->guessExtension() ?? $file->getClientOriginalExtension();
    }
}
