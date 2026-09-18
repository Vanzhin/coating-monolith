<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

use App\Shared\Infrastructure\Exception\AppException;

enum TemplateFormat: string
{
    case Docx = 'docx';
    case Xlsx = 'xlsx';

    public static function fromExtension(string $extension): self
    {
        return self::tryFrom(strtolower($extension))
            ?? throw new AppException(sprintf('Неподдерживаемый формат шаблона: «%s».', $extension));
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
