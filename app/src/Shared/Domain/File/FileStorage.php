<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface FileStorage
{
    /** Прямая загрузка: файл пришёл в сабмите и сразу привязан к сущности. */
    public function store(FilePurpose $purpose, string $ownerId, UploadedFile $file): StoredFile;

    /** Двухфазная загрузка: файл во временную зону с TTL, привязка — позже через promote(). */
    public function stage(string $uploaderId, UploadedFile $file): StoredFile;

    public function promote(string $uuid, FilePurpose $purpose, string $ownerId): StoredFile;

    public function get(string $uuid): ?StoredFile;

    /** @return StoredFile[] */
    public function byOwner(FilePurpose $purpose, string $ownerId): array;

    /** @return resource */
    public function readStream(string $uuid);

    /** Материализует временную локальную копию (для либ, требующих путь); вызывающий сам удаляет. */
    public function toLocalTempFile(string $uuid): string;

    public function remove(string $uuid): void;

    public function removeByOwner(FilePurpose $purpose, string $ownerId): void;

    /** Удаляет просроченные tmp-файлы (байты + записи реестра); возвращает число. */
    public function purgeExpired(): int;
}
