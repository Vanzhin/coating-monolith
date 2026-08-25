<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Aggregate\Document;

/**
 * Вид документа.
 */
enum DocumentKind: string
{
    case Conclusion = 'conclusion';
    case Certificate = 'certificate';
    case Protocol = 'protocol';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Conclusion => 'Заключение',
            self::Certificate => 'Сертификат',
            self::Protocol => 'Протокол',
            self::Other => 'Другое',
        };
    }
}
