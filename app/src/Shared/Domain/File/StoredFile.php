<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Запись реестра файлов. Мутируется только на promote (tmp → привязанный к сущности).
 * Форма ключа хранилища живёт здесь; физику (байты) держит FlysystemFileStorage.
 */
class StoredFile
{
    public const STATUS_STAGED = 'staged';
    public const STATUS_STORED = 'stored';

    private function __construct(
        private readonly string $id,
        private string $status,
        private ?string $purpose,
        private ?string $ownerId,
        private readonly ?string $uploaderId,
        private readonly string $originalName,
        private readonly string $mime,
        private readonly string $extension,
        private readonly int $size,
        private string $storageKey,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $expiresAt,
    ) {
    }

    public static function staged(
        string $id,
        string $uploaderId,
        string $originalName,
        string $mime,
        string $extension,
        int $size,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $id, self::STATUS_STAGED, null, null, $uploaderId,
            $originalName, $mime, $extension, $size,
            self::tmpKey($uploaderId, $id, $extension), $createdAt, $expiresAt,
        );
    }

    public static function stored(
        string $id,
        FilePurpose $purpose,
        string $ownerId,
        string $originalName,
        string $mime,
        string $extension,
        int $size,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id, self::STATUS_STORED, $purpose->key(), $ownerId, null,
            $originalName, $mime, $extension, $size,
            self::storedKey($purpose, $ownerId, $id, $extension), $createdAt, null,
        );
    }

    public function promote(FilePurpose $purpose, string $ownerId): void
    {
        if (self::STATUS_STAGED !== $this->status) {
            throw new AppException('Файл уже привязан и не может быть привязан повторно.');
        }
        $this->status = self::STATUS_STORED;
        $this->purpose = $purpose->key();
        $this->ownerId = $ownerId;
        $this->storageKey = self::storedKey($purpose, $ownerId, $this->id, $this->extension);
        $this->expiresAt = null;
    }

    private static function tmpKey(string $uploaderId, string $id, string $ext): string
    {
        return sprintf('tmp/%s/%s.%s', $uploaderId, $id, $ext);
    }

    private static function storedKey(FilePurpose $purpose, string $ownerId, string $id, string $ext): string
    {
        return sprintf('%s/%s/%s.%s', $purpose->storagePrefix(), $ownerId, $id, $ext);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function purpose(): ?string
    {
        return $this->purpose;
    }

    public function ownerId(): ?string
    {
        return $this->ownerId;
    }

    public function uploaderId(): ?string
    {
        return $this->uploaderId;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
