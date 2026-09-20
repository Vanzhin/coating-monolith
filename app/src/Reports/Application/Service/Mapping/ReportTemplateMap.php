<?php

declare(strict_types=1);

namespace App\Reports\Application\Service\Mapping;

use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\FieldType;

/**
 * Маппинг «отчёт → переменные шаблона» как ДАННЫЕ. Сейчас дефолт генерится из схемы блоков по
 * конвенции (шапка + {block}_{field}; слои → префикс block), поэтому вывод совпадает с прежним
 * проектором. Позже конструктор отчётов заменит эту сборку на хранимый редактируемый список записей.
 */
final readonly class ReportTemplateMap
{
    public function __construct(private BlockRegistry $registry)
    {
    }

    /**
     * Реквизиты-шапка — не зависят от типа.
     *
     * @return list<TemplateMapEntry>
     */
    public function headerEntries(): array
    {
        return [
            TemplateMapEntry::header('actNumber', 'act_number'),
            TemplateMapEntry::header('reportDate', 'report_date'),
            TemplateMapEntry::header('reportType', 'report_type'),
            TemplateMapEntry::header('status', 'status'),
            TemplateMapEntry::header('address', 'address'),
            TemplateMapEntry::header('workPeriod', 'work_period'),
            TemplateMapEntry::header('projectTitle', 'project_title'),
            TemplateMapEntry::header('customerTitle', 'customer_title'),
            TemplateMapEntry::header('contractorTitle', 'contractor_title'),
            TemplateMapEntry::header('systemTitle', 'system_title'),
        ];
    }

    /**
     * Полный маппинг типа: шапка + поля блоков его композиции.
     *
     * @return list<TemplateMapEntry>
     */
    public function entriesFor(ReportType $type): array
    {
        $entries = $this->headerEntries();
        foreach ($type->blockKeys() as $blockKey) {
            foreach ($this->registry->get($blockKey)->fields() as $field) {
                // Слои: переменная = префикс (block), маппер раскроет в block_layerN_sub.
                $variable = FieldType::Layers === $field->type
                    ? $blockKey->value
                    : $blockKey->value.'_'.$field->key;
                $entries[] = TemplateMapEntry::field($blockKey->value, $field->key, $variable);
            }
        }

        return $entries;
    }
}
