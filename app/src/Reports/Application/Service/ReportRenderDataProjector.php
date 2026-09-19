<?php

declare(strict_types=1);

namespace App\Reports\Application\Service;

use App\Reports\Application\Service\Mapping\ReportTemplateMap;
use App\Reports\Application\Service\Mapping\TemplateSource;
use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TextValue;

/**
 * Проектор отчёта в плоский RenderData для движка шаблонов — **data-driven по маппингу**
 * (ReportTemplateMap: «источник → переменная»). Форматирование значений по типу поля — здесь
 * (даты, списки→текст, слои→индексные ключи, ссылки→title). Presence-driven: пустые не кладём.
 * Дефолтный маппинг = конвенция ({block}_{field}), поэтому ключи прежние; конструктор отчётов
 * позже подменит источник маппинга (БД/конфиг), проектор не меняется.
 */
final readonly class ReportRenderDataProjector
{
    public function __construct(
        private BlockRegistry $registry,
        private ReportTemplateMap $map,
    ) {
    }

    public function project(Report $report): RenderData
    {
        $type = $report->getType();
        $entries = null !== $type ? $this->map->entriesFor($type) : $this->map->headerEntries();
        $content = $report->getContent();
        $values = [];

        foreach ($entries as $entry) {
            if (TemplateSource::Header === $entry->source) {
                $this->put($values, $entry->variable, $this->headerValue($report, (string) $entry->headerAttr));

                continue;
            }

            $field = $this->field((string) $entry->blockKey, (string) $entry->fieldKey);
            if (null === $field) {
                continue;
            }
            $blockData = is_array($content[$entry->blockKey] ?? null) ? $content[$entry->blockKey] : [];
            $raw = $blockData[$field->key] ?? null;

            if (FieldType::Layers === $field->type) {
                $this->projectLayers($values, $entry->variable, $field, $raw);
            } elseif (FieldType::ListRows === $field->type) {
                $this->put($values, $entry->variable, $this->formatList($field, $raw));
            } elseif ($field->type->isScalar()) {
                $this->put($values, $entry->variable, $this->formatScalar($field, $raw));
            }
            // ссылки/медиа (CoatingRef/PhotoSlot) в документ пока не проецируются
        }

        return new RenderData($values);
    }

    private function headerValue(Report $report, string $attr): ?string
    {
        return match ($attr) {
            'actNumber' => $report->getActNumber(),
            'reportDate' => $report->getReportDate()?->format('d.m.Y'),
            'reportType' => $report->getType()?->label(),
            'status' => $report->getStatus()->label(),
            'projectTitle' => $report->getProject()?->title,
            'customerTitle' => $report->getCustomer()?->title,
            'contractorTitle' => $report->getContractor()?->title,
            'systemTitle' => $report->getSystem()?->title,
            default => null,
        };
    }

    private function field(string $blockKey, string $fieldKey): ?Field
    {
        $key = BlockKey::tryFrom($blockKey);
        if (null === $key || !$this->registry->has($key)) {
            return null;
        }
        foreach ($this->registry->get($key)->fields() as $field) {
            if ($field->key === $fieldKey) {
                return $field;
            }
        }

        return null;
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
     * Под-поле строки/слоя: ссылка на каталог ({id, title}) → title-снимок (id — бэклинк, в документ
     * не идёт); иначе — обычное скалярное форматирование.
     */
    private function formatSubValue(Field $field, mixed $value): ?string
    {
        if (FieldType::CoatingRef === $field->type || FieldType::ColorRef === $field->type) {
            return is_array($value) && isset($value['title']) && is_string($value['title']) && '' !== $value['title']
                ? $value['title']
                : null;
        }

        return $this->formatScalar($field, $value);
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
                $this->put($values, sprintf('%s_layer%d_%s', $blockKeyValue, $index, $sub->key), $this->formatSubValue($sub, $row[$sub->key] ?? null));
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
