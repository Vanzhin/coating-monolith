<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block;

/**
 * Описание одного поля блока: ключ, тип, подпись, обязательность. Для enum — допустимые значения
 * (options) и опц. ссылка на норму (standard, напр. «ISO 8501-1»); для number — единица (unit).
 */
final readonly class Field
{
    /**
     * @param list<string> $options допустимые значения для FieldType::Enum
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $label,
        public bool $required = false,
        public ?string $unit = null,
        public array $options = [],
        public ?string $standard = null,
    ) {
    }
}
