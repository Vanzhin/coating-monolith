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
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\ValueObject\DateTimeInterval;

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
                $this->projectRepeat($values, (string) $entry->blockKey, $field, $raw);
            } elseif (FieldType::StringList === $field->type) {
                $this->put($values, $entry->variable, $this->formatStringList($raw));
                $this->projectStringListRepeat($values, (string) $entry->blockKey, $raw);
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
            'address' => $report->getAddress(),
            'workPeriod' => $this->formatPeriod($report->getWorkPeriod()),
            default => null,
        };
    }

    /** Период работ → «с ДД.ММ.ГГГГ по ДД.ММ.ГГГГ» (открытый с одной стороны — только одна граница). */
    private function formatPeriod(?DateTimeInterval $period): ?string
    {
        if (null === $period) {
            return null;
        }
        $from = $period->getFrom()?->format('d.m.Y');
        $to = $period->getTo()?->format('d.m.Y');
        if (null !== $from && null !== $to) {
            return sprintf('с %s по %s', $from, $to);
        }

        return null !== $from ? 'с '.$from : 'по '.$to;
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
            $base = sprintf('%s_layer%d', $blockKeyValue, $index);
            foreach ($field->itemFields as $sub) {
                $subValue = $row[$sub->key] ?? null;
                $prefix = $base.'_'.$sub->key;
                if (FieldType::Thickness === $sub->type) {
                    $this->projectThickness($values, $prefix, $subValue);

                    continue;
                }
                if (FieldType::NumberRange === $sub->type) {
                    $this->projectNumberRange($values, $prefix, $subValue);

                    continue;
                }
                if (FieldType::Thinner === $sub->type) {
                    $this->projectThinner($values, $prefix, $subValue);

                    continue;
                }
                if (FieldType::DateTimeRange === $sub->type) {
                    // Период нанесения → дата (последняя) в {base}_date, интервал времени в {base}_time.
                    $this->projectAppliedPeriod($values, $base, $subValue);

                    continue;
                }
                $this->put($values, $prefix, $this->formatSubValue($sub, $subValue));
            }
        }
        if ($index > 0) {
            $this->put($values, $blockKeyValue.'_layer_count', (string) $index);
        }
    }

    /**
     * Толщина слоя {min,max,mean} → ключи `{prefix}_min|_max|_mean` + `{prefix}_range` («мин–макс»).
     * range/mean совпадают со старыми плейсхолдерами dry_film_range/dry_film_mean — шаблон не меняется.
     *
     * @param array<string, TextValue> $values
     */
    private function projectThickness(array &$values, string $prefix, mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        $min = $value['min'] ?? null;
        $max = $value['max'] ?? null;
        $this->put($values, $prefix.'_min', is_scalar($min) ? (string) $min : null);
        $this->put($values, $prefix.'_max', is_scalar($max) ? (string) $max : null);
        $this->put($values, $prefix.'_mean', is_scalar($value['mean'] ?? null) ? (string) $value['mean'] : null);
        if (is_scalar($min) && '' !== (string) $min && is_scalar($max) && '' !== (string) $max) {
            $this->put($values, $prefix.'_range', sprintf('%s–%s', (string) $min, (string) $max));
        }
    }

    /**
     * Диапазон толщины без среднего {min,max} → ключи `{prefix}_min|_max` + `{prefix}_range` («мин–макс»).
     * Для мокрого слоя в акт обычно идёт `{prefix}_range`; отдельные границы — на будущее.
     *
     * @param array<string, TextValue> $values
     */
    private function projectNumberRange(array &$values, string $prefix, mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        $min = $value['min'] ?? null;
        $max = $value['max'] ?? null;
        $this->put($values, $prefix.'_min', is_scalar($min) ? (string) $min : null);
        $this->put($values, $prefix.'_max', is_scalar($max) ? (string) $max : null);
        if (is_scalar($min) && '' !== (string) $min && is_scalar($max) && '' !== (string) $max) {
            $this->put($values, $prefix.'_range', sprintf('%s–%s', (string) $min, (string) $max));
        }
    }

    /**
     * Разбавитель {name,batch,percent} → под-ключи `{prefix}_name|_batch|_percent` + собранная строка
     * `{prefix}` вида «7% Название (№ партии XXX)» для плейсхолдера {{..._thinner}}.
     *
     * @param array<string, TextValue> $values
     */
    private function projectThinner(array &$values, string $prefix, mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        $name = trim((string) ($value['name'] ?? ''));
        $batch = trim((string) ($value['batch'] ?? ''));
        $percent = $value['percent'] ?? null;
        $percentStr = is_scalar($percent) ? trim((string) $percent) : '';

        $this->put($values, $prefix.'_name', '' !== $name ? $name : null);
        $this->put($values, $prefix.'_batch', '' !== $batch ? $batch : null);
        $this->put($values, $prefix.'_percent', '' !== $percentStr ? $percentStr : null);

        $parts = [];
        if ('' !== $percentStr) {
            $parts[] = $percentStr.'%';
        }
        if ('' !== $name) {
            $parts[] = $name;
        }
        $combined = implode(' ', $parts);
        if ('' !== $batch) {
            $combined = trim($combined.sprintf(' (№ партии %s)', $batch));
        }
        $this->put($values, $prefix, '' !== $combined ? $combined : null);
    }

    /**
     * Период нанесения {from,to} (дата+время) → в акт разными частями: `{base}_date` — дата (последняя,
     * т.е. `to`; при отсутствии — `from`) дд.мм.гггг; `{base}_time` — интервал «ЧЧ:ММ–ЧЧ:ММ». Плюс
     * полные `{base}_datetime_from/to` на будущее.
     *
     * @param array<string, TextValue> $values
     */
    private function projectAppliedPeriod(array &$values, string $base, mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        $from = $this->parseDateTime($value['from'] ?? null);
        $to = $this->parseDateTime($value['to'] ?? null);
        $lastDate = $to ?? $from;

        $this->put($values, $base.'_date', $lastDate?->format('d.m.Y'));

        $time = null;
        if (null !== $from && null !== $to) {
            $time = $from->format('H:i').'–'.$to->format('H:i');
        } elseif (null !== $from) {
            $time = $from->format('H:i');
        } elseif (null !== $to) {
            $time = $to->format('H:i');
        }
        $this->put($values, $base.'_time', $time);

        $this->put($values, $base.'_datetime_from', $from?->format('d.m.Y H:i'));
        $this->put($values, $base.'_datetime_to', $to?->format('d.m.Y H:i'));
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Список строк-объектов → повторяемая группа (RepeatValue) под ключом блока: по одной записи на строку,
     * подключи = ключи itemFields (пустые подполя → '', чтобы драйвер очистил все `{{group.sub#i}}`).
     * Пустой список — группу не кладём (драйвер удалит строку-шаблон). Идёт рядом с плоским `{block}_items`.
     *
     * @param array<string, \App\Shared\Domain\Templating\TemplateValue> $values
     */
    private function projectRepeat(array &$values, string $groupKey, Field $field, mixed $value): void
    {
        if (!is_array($value) || [] === $value) {
            return;
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = [];
            foreach ($field->itemFields as $sub) {
                $formatted = $this->formatSubValue($sub, $row[$sub->key] ?? null);
                $mapped[$sub->key] = null !== $formatted ? $formatted : '';
            }
            $rows[] = $mapped;
        }
        if ([] !== $rows) {
            $values[$groupKey] = new RepeatValue($rows);
        }
    }

    /**
     * Список строк → повторяемая группа под ключом блока: по одной записи на непустой пункт, подключ
     * `text`. Позволяет вставить рекомендации/выводы настоящим списком Word (cloneBlock по `{{group.text}}`)
     * или строками таблицы. Рядом остаётся плоский нумерованный `{block}_items`.
     *
     * @param array<string, \App\Shared\Domain\Templating\TemplateValue> $values
     */
    private function projectStringListRepeat(array &$values, string $groupKey, mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        $rows = [];
        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $rows[] = ['text' => trim($item)];
            }
        }
        if ([] !== $rows) {
            $values[$groupKey] = new RepeatValue($rows);
        }
    }

    /**
     * Список строк (рекомендации/выводы) → нумерованный список: «1. …\n2. …». Пустые пункты
     * отбрасываем. Перенос строки движок Word превращает в мягкий перевод (setValue → <w:br/>).
     */
    private function formatStringList(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        $lines = [];
        $n = 0;
        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $lines[] = sprintf('%d. %s', ++$n, trim($item));
            }
        }

        return [] === $lines ? null : implode("\n", $lines);
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
