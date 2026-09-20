<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block;

/**
 * Определение блока отчёта: ключ, заголовок, набор полей. Каждый блок — класс, реализующий этот
 * интерфейс, регистрируется tagged-iterator'ом (см. BlockRegistry). Схема блока — код (стабильна,
 * типобезопасна), не конфиг в БД.
 */
interface BlockDefinition
{
    public function key(): BlockKey;

    public function title(): string;

    /**
     * @return list<Field>
     */
    public function fields(): array;
}
