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
    /** @var list<array<string, string|ImageValue>> */
    public array $rows;

    /**
     * Ячейка строки — строка (обычный текст) ИЛИ ImageValue (картинка, напр. фото отчёта): драйвер
     * заливает текст через setValue, картинку — через setImageValue в клон строки/блока.
     *
     * @param list<array<string, string|ImageValue>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
}
