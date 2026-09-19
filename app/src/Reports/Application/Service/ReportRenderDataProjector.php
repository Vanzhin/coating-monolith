<?php

declare(strict_types=1);

namespace App\Reports\Application\Service;

use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TextValue;

/**
 * Проектор отчёта в плоский RenderData для движка шаблонов. Ключи (латиница snake_case):
 * шапка — act_number/report_date/report_type/status/project_title/customer_title/contractor_title/
 * system_title; блоки — «{blockKey}_{fieldKey}» (напр. surface_prep_rustGrade, conclusion_text).
 * Presence-driven: пустые не кладём (в шаблоне это опциональные {{x?}}). В 3a — только скаляры.
 * Форматирование значений на поле/тип — здесь (override под аудит-форматтеры добавим позже).
 */
final readonly class ReportRenderDataProjector
{
    public function __construct(private BlockRegistry $registry)
    {
    }

    public function project(Report $report): RenderData
    {
        $values = [];

        $this->put($values, 'act_number', $report->getActNumber());
        $this->put($values, 'report_date', $report->getReportDate()?->format('d.m.Y'));
        $this->put($values, 'report_type', $report->getType()?->label());
        $this->put($values, 'status', $report->getStatus()->label());
        $this->put($values, 'project_title', $report->getProject()?->title);
        $this->put($values, 'customer_title', $report->getCustomer()?->title);
        $this->put($values, 'contractor_title', $report->getContractor()?->title);
        $this->put($values, 'system_title', $report->getSystem()?->title);

        $type = $report->getType();
        if (null !== $type) {
            $content = $report->getContent();
            foreach ($type->blockKeys() as $blockKey) {
                $definition = $this->registry->get($blockKey);
                $blockData = $content[$blockKey->value] ?? [];
                if (!is_array($blockData)) {
                    continue;
                }
                foreach ($definition->fields() as $field) {
                    if (!$field->type->isScalar()) {
                        continue; // композиты/медиа — в 3b
                    }
                    $this->put($values, $blockKey->value.'_'.$field->key, $this->formatScalar($field, $blockData[$field->key] ?? null));
                }
            }
        }

        return new RenderData($values);
    }

    /**
     * @param array<string, TextValue> $values
     */
    private function put(array &$values, string $key, ?string $value): void
    {
        if (null !== $value && '' !== $value) {
            $values[$key] = new TextValue($value);
        }
    }

    private function formatScalar(Field $field, mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return match ($field->type) {
            FieldType::Bool => $value ? 'Да' : 'Нет',
            default => is_scalar($value) ? (string) $value : null,
        };
    }
}
