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
 * Presence-driven: пустые не кладём (в шаблоне это опциональные {{x?}}). Слои → индексные ключи
 * application_layerN_* + application_layer_count; списки → текст.
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
                    if (FieldType::Layers === $field->type) {
                        $this->projectLayers($values, $blockKey->value, $field, $blockData[$field->key] ?? null);

                        continue;
                    }
                    if (FieldType::ListRows === $field->type) {
                        $this->put($values, $blockKey->value.'_'.$field->key, $this->formatList($field, $blockData[$field->key] ?? null));

                        continue;
                    }
                    if (!$field->type->isScalar()) {
                        continue; // ссылки/медиа — позже
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

    /**
     * Слои → индексированные ключи (движок плоский, без повтора): для слоя N (с 1) и под-поля f —
     * ключ «{block}_layer{N}_{f}», плюс «{block}_layer_count». По счётчику потребитель выбирает шаблон.
     *
     * @param array<string, TextValue> $values
     */
    private function projectLayers(array &$values, string $blockKeyValue, Field $field, mixed $value): void
    {
        if (!is_array($value) || [] === $value) {
            return;
        }

        $index = 0;
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            ++$index;
            foreach ($field->itemFields as $sub) {
                $this->put($values, sprintf('%s_layer%d_%s', $blockKeyValue, $index, $sub->key), $this->formatScalar($sub, $row[$sub->key] ?? null));
            }
        }
        if ($index > 0) {
            $this->put($values, $blockKeyValue.'_layer_count', (string) $index);
        }
    }

    /**
     * Список строк → текст (движок плоский, без повтора): значения под-полей строки через « — »,
     * строки — через перевод строки. Форматирование базовое, уточним override'ами позже.
     */
    private function formatList(Field $field, mixed $value): ?string
    {
        if (!is_array($value) || [] === $value) {
            return null;
        }

        $lines = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parts = [];
            foreach ($field->itemFields as $sub) {
                $subValue = $row[$sub->key] ?? null;
                if (null !== $subValue && '' !== $subValue && is_scalar($subValue)) {
                    $parts[] = (string) $subValue;
                }
            }
            if ([] !== $parts) {
                $lines[] = implode(' — ', $parts);
            }
        }

        return [] === $lines ? null : implode("\n", $lines);
    }
}
