<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

/**
 * Готовый заполненный документ: байты содержимого + формат (задаёт расширение и mime).
 * Хранить/скачать/отдать на конвертацию решает вызывающий.
 */
final readonly class RenderedDocument
{
    public function __construct(
        public string $content,
        public TemplateFormat $format,
    ) {
    }

    public function extension(): string
    {
        return $this->format->value;
    }

    public function mimeType(): string
    {
        return $this->format->mimeType();
    }
}
