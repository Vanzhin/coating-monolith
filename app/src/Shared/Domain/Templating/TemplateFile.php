<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Путь к файлу-шаблону + его формат. Валидирует только форму (непустой путь + известное расширение).
 * Существование/читаемость файла проверяет драйвер в момент validate/render.
 */
final readonly class TemplateFile
{
    public TemplateFormat $format;

    public function __construct(public string $path)
    {
        if ('' === trim($path)) {
            throw new AppException('Путь к шаблону не может быть пустым.');
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ('' === $extension) {
            throw new AppException(sprintf('У шаблона нет расширения: «%s».', $path));
        }

        $this->format = TemplateFormat::fromExtension($extension);
    }
}
