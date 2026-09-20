<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

/**
 * Повторяемая группа: список строк, каждая — набор «подключ → готовое значение». Драйвер клонирует
 * строку таблицы по количеству строк и заполняет плейсхолдеры `{{group.sub}}` значениями по подключам.
 * Форматирование значений — забота вызывающего (сюда приходят готовые строки).
 */
final readonly class RepeatValue implements TemplateValue
{
    /** @var list<array<string, string>> */
    public array $rows;

    /**
     * @param list<array<string, string>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
}
