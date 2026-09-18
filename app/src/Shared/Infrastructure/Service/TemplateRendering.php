<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RenderedDocument;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateRenderer;
use App\Shared\Domain\Templating\TemplateVariable;
use App\Shared\Domain\Templating\ValidationResult;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Универсальная точка входа: по формату шаблона выбирает подходящий драйвер и делегирует ему.
 * Потребитель передаёт TemplateFile и про формат не думает.
 */
final class TemplateRendering
{
    /**
     * @param iterable<TemplateRenderer> $renderers
     */
    public function __construct(private readonly iterable $renderers)
    {
    }

    /**
     * @return list<TemplateVariable>
     */
    public function variables(TemplateFile $template): array
    {
        return $this->rendererFor($template)->variables($template);
    }

    public function validate(TemplateFile $template, RenderData $data): ValidationResult
    {
        return $this->rendererFor($template)->validate($template, $data);
    }

    public function render(TemplateFile $template, RenderData $data): RenderedDocument
    {
        return $this->rendererFor($template)->render($template, $data);
    }

    private function rendererFor(TemplateFile $template): TemplateRenderer
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($template)) {
                return $renderer;
            }
        }

        throw new AppException(sprintf('Нет драйвера для формата «%s».', $template->format->value));
    }
}
