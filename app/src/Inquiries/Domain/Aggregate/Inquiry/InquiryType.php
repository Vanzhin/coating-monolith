<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Тип запроса. Домен слеп к смыслу типа — процессор под тип живёт в Application (реестр по типу).
 * Сейчас один тип; второй («подбор покрытия» и т.п.) добавляется кейсом + своим процессором.
 */
enum InquiryType: string
{
    case SystemSelection = 'system_selection';

    public function label(): string
    {
        return match ($this) {
            self::SystemSelection => 'Подбор системы',
        };
    }
}
