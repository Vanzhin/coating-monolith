<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Repository;

/**
 * Статус срока действия документа для фильтра списка.
 * Valid — есть дата и она не прошла; Expired — дата прошла; Perpetual — даты нет (бессрочный).
 */
enum DocumentExpiryStatus: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Perpetual = 'perpetual';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Действует',
            self::Expired => 'Просрочен',
            self::Perpetual => 'Бессрочный',
        };
    }
}
