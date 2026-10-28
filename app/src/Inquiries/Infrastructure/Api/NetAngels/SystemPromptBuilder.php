<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Api\NetAngels;

/**
 * Собирает системный промпт извлечения. Это знание о предметной области (поля фильтра,
 * правила распознавания), не о провайдере — отдельный класс, версионируется. Текст лежит
 * рядом в Resources/system-prompt.<version>.txt (обкатанная версия из docs/plans).
 */
final class SystemPromptBuilder
{
    public const VERSION = 'v2';

    public function build(): string
    {
        $path = __DIR__.'/Resources/system-prompt.'.self::VERSION.'.txt';
        $content = file_get_contents($path);

        if (false === $content) {
            throw new \RuntimeException("Не найден системный промпт извлечения: {$path}");
        }

        return $content;
    }

    public function version(): string
    {
        return self::VERSION;
    }
}
