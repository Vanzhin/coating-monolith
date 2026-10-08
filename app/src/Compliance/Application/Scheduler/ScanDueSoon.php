<?php

declare(strict_types=1);

namespace App\Compliance\Application\Scheduler;

/** Сигнал ежедневного прохода сроков (публикуется Symfony Scheduler). Без полей — момент берём на обработке. */
final readonly class ScanDueSoon
{
}
