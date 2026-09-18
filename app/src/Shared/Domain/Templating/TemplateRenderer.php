<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

/**
 * Порт движка заполнения шаблонов. Реализуется драйвером на формат (docx, xlsx).
 * Как драйвер ищет и подставляет — его дело; контракт для всех форматов один.
 */
interface TemplateRenderer
{
    public function supports(TemplateFile $template): bool;

    /**
     * Плейсхолдеры шаблона с признаком опциональности.
     *
     * @return list<TemplateVariable>
     */
    public function variables(TemplateFile $template): array;

    public function validate(TemplateFile $template, RenderData $data): ValidationResult;

    public function render(TemplateFile $template, RenderData $data): RenderedDocument;
}
