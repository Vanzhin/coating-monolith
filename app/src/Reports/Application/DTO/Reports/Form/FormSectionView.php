<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Reports\Form;

/** Секция формы = один блок отчёта. readOnly — засеянные/неправимые блоки (Система-план). */
final readonly class FormSectionView
{
    /**
     * @param list<FormFieldView> $fields
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $fields,
        public bool $readOnly = false,
    ) {
    }
}
