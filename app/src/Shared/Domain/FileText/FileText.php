<?php

declare(strict_types=1);

namespace App\Shared\Domain\FileText;

/**
 * Результат извлечения текста из одного файла. text непустой только при EXTRACTED;
 * truncated — сработал ли safety-cap длины; error — причина при ERROR.
 */
final readonly class FileText
{
    public function __construct(
        public string $text,
        public FileTextStatus $status,
        public bool $truncated = false,
        public ?string $error = null,
    ) {
    }

    public static function extracted(string $text, bool $truncated): self
    {
        return new self($text, FileTextStatus::EXTRACTED, $truncated);
    }

    public static function scan(): self
    {
        return new self('', FileTextStatus::SCAN);
    }

    public static function error(string $reason): self
    {
        return new self('', FileTextStatus::ERROR, false, $reason);
    }

    public function isExtracted(): bool
    {
        return FileTextStatus::EXTRACTED === $this->status;
    }
}
