<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Метаданные вызова извлечения — для аудита и анализа (храним в Inquiry, план 3). strategy
 * различает, чем получен результат: "llm:netangels" сегодня, "cache"/"template:…" позже.
 */
final readonly class ExtractionMetadata
{
    public function __construct(
        public string $strategy,
        public ?string $model = null,
        public ?string $promptVersion = null,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?int $durationMs = null,
    ) {
    }
}
