<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

interface StoredFileRepositoryInterface
{
    public function add(StoredFile $file): void;

    public function get(string $id): ?StoredFile;

    /** @return StoredFile[] */
    public function byOwner(string $purposeKey, string $ownerId): array;

    /** @return StoredFile[] */
    public function expiredStaged(\DateTimeImmutable $now): array;

    public function remove(StoredFile $file): void;
}
