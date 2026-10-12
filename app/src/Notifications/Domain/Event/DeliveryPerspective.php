<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/**
 * Перспектива доставки: получатель — субъект события (о нём самом) или надзор (о других).
 * Определяет персональный текст против сводки. Не хранится.
 */
enum DeliveryPerspective
{
    case Personal;
    case Oversight;
}
