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
     * @param list<string> $options    допустимые значения для FieldType::Enum
     * @param list<Field>  $itemFields под-поля строки для композитов (ListRows/Layers)
     * @param int          $group      номер визуальной подгруппы в строке композита (0 — без группировки);
     *                                 соседние поля с одним номером рендерятся вместе, смена номера — разделитель
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $label,
        public bool $required = false,
        public ?string $unit = null,
        public array $options = [],
        public ?string $standard = null,
        public array $itemFields = [],
        public bool $positive = false,
        public bool $percent = false,
        public bool $computed = false,
        public int $group = 0,
        public int $rows = 2,
    ) {
    }
}
