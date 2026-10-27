<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Итог извлечения: параметры + сырой ответ (храним как есть для разбора ошибок) + метаданные
 * вызова + список отброшенных значений (вне enum) — для показа инженеру «что отбросили».
 */
final readonly class ExtractionResult
{
    /** @param list<string> $droppedValues */
    public function __construct(
        public ExtractedParameters $parameters,
        public string $rawJson,
        public ExtractionMetadata $metadata,
        public array $droppedValues = [],
    ) {
    }
}
