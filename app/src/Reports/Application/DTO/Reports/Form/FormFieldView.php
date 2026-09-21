<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Reports\Form;

/** Поле блока для рендера формы: тип-виджет, подпись, текущее значение, под-поля (для списков). */
final readonly class FormFieldView
{
    /**
     * @param list<string>        $options    варианты для enum
     * @param list<FormFieldView> $itemFields под-поля строки (для list)
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public bool $required,
        public ?string $unit,
        public array $options,
        public array $itemFields,
        public mixed $value,
        public bool $positive = false,
        public bool $percent = false,
        public bool $computed = false,
        public int $group = 0,
        public int $rows = 2,
    ) {
    }
}
