<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Service;

use App\Reports\Application\UseCase\Query\GetReportRenderData\GetReportRenderDataQueryResult;
use App\Shared\Infrastructure\Service\TemplateRendering;

/**
 * Готовность отчёта к генерации: сверяет данные с обязательными плейсхолдерами выбранного шаблона
 * (движок сам знает, что required) и возвращает человекочитаемый список недостающего. Пусто = готов.
 */
final readonly class ReportReadinessChecker
{
    public function __construct(
        private ReportTemplateLocator $locator,
        private TemplateRendering $rendering,
    ) {
    }

    /**
     * @return list<string> человекочитаемые названия недостающих полей (пусто — можно формировать)
     */
    public function missing(GetReportRenderDataQueryResult $render): array
    {
        $template = $this->locator->locate($render->type, $render->layerCount);
        $result = $this->rendering->validate($template, $render->data);

        return array_map([$this, 'humanize'], [...$result->missing, ...$result->unresolvedImages]);
    }

    private function humanize(string $variable): string
    {
        if ('act_number' === $variable) {
            return '№ акта';
        }
        if (preg_match('/^application_layer(\d+)_material$/', $variable, $m)) {
            return 'Материал слоя '.$m[1].' (нанесение)';
        }
        if (preg_match('/^system_layer(\d+)_material$/', $variable, $m)) {
            return 'Материал слоя '.$m[1].' (система)';
        }

        return $variable;
    }
}
