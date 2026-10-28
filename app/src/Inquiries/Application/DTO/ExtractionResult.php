<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Итог извлечения: параметры + сырой ответ (храним как есть для разбора ошибок) + метаданные
 * вызова + отброшенные значения (вне enum) + conflicts — расхождения тело↔вложение, где взяли
 * приоритетный источник (вложение); и то, и другое показываем инженеру через warnings.
 */
final readonly class ExtractionResult
{
    /**
     * @param list<string> $droppedValues
     * @param list<string> $conflicts     заметки о расхождениях источников (напр. «T: взято из вложения 80, в теле 90»)
     */
    public function __construct(
        public ExtractedParameters $parameters,
        public string $rawJson,
        public ExtractionMetadata $metadata,
        public array $droppedValues = [],
        public array $conflicts = [],
    ) {
    }
}
