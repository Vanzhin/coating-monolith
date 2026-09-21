<?php

declare(strict_types=1);

namespace App\Reports\Application\Service;

use App\Reports\Application\DTO\Reports\Form\FormFieldView;
use App\Reports\Application\DTO\Reports\Form\FormSectionView;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\Field;

/**
 * Строит секции формы заполнения из схемы блоков типа отчёта + текущего content. Шаблон рендерит
 * виджеты по типу поля. «Система (план)» — readOnly (засеяна при создании, не правится).
 */
final readonly class ReportFormPresenter
{
    /** Блоки, которые не редактируются пользователем (засеяны/производны). */
    private const READ_ONLY = [BlockKey::System];

    public function __construct(private BlockRegistry $registry)
    {
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<FormSectionView>
     */
    public function sections(ReportType $type, array $content): array
    {
        $sections = [];
        foreach ($type->blockKeys() as $blockKey) {
            $definition = $this->registry->get($blockKey);
            $blockData = is_array($content[$blockKey->value] ?? null) ? $content[$blockKey->value] : [];

            $fields = [];
            foreach ($definition->fields() as $field) {
                $fields[] = $this->field($field, $blockData[$field->key] ?? null);
            }

            $sections[] = new FormSectionView(
                $blockKey->value,
                $definition->title(),
                $fields,
                in_array($blockKey, self::READ_ONLY, true),
            );
        }

        return $sections;
    }

    private function field(Field $field, mixed $value): FormFieldView
    {
        $itemFields = [];
        foreach ($field->itemFields as $sub) {
            $itemFields[] = $this->field($sub, null);
        }

        return new FormFieldView(
            $field->key,
            $field->type->value,
            $field->label,
            $field->required,
            $field->unit,
            $field->options,
            $itemFields,
            $value,
            $field->positive,
            $field->percent,
            $field->computed,
            $field->group,
            $field->rows,
        );
    }
}
