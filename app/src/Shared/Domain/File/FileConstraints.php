<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class FileConstraints
{
    /**
     * @param list<string> $mimeTypes
     */
    public function __construct(
        private int $maxBytes,
        private array $mimeTypes,
        private ?int $maxWidth = null,
        private ?int $maxHeight = null,
    ) {
        if ($maxBytes <= 0) {
            throw new AppException('Лимит размера файла должен быть положительным.');
        }
        if ([] === $mimeTypes) {
            throw new AppException('Список допустимых типов файла не может быть пустым.');
        }
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return $this->mimeTypes;
    }

    public function maxWidth(): ?int
    {
        return $this->maxWidth;
    }

    public function maxHeight(): ?int
    {
        return $this->maxHeight;
    }
}
