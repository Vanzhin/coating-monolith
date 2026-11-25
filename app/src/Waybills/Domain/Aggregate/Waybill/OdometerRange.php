<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\Aggregate\ValueObject\IntRange;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class OdometerRange extends IntRange
{
    public function __construct(int $start, int $end)
    {
        if ($start < 0) {
            throw new AppException('Начальное показание одометра не может быть отрицательным.');
        }

        parent::__construct($start, $end);
    }

    public function distance(): int
    {
        return $this->length();
    }
}
