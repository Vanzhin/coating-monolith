<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Service;

use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Находит .docx-шаблон акта по типу и числу слоёв: сперва `{type}_{N}layer.docx`, иначе `{type}.docx`.
 * Файлы — в Resources/templates (стартовые сгенерированы под контракт плейсхолдеров, заменяются реальными).
 */
final readonly class ReportTemplateLocator
{
    public function __construct(private string $templatesDir)
    {
    }

    public function locate(ReportType $type, int $layerCount): TemplateFile
    {
        $candidates = [];
        if ($layerCount > 0) {
            $candidates[] = sprintf('%s/%s_%dlayer.docx', $this->templatesDir, $type->value, $layerCount);
        }
        $candidates[] = sprintf('%s/%s.docx', $this->templatesDir, $type->value);

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return new TemplateFile($path);
            }
        }

        throw new AppException(sprintf('Шаблон документа для «%s» не найден.', $type->label()));
    }
}
