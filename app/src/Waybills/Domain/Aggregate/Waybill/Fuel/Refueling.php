<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Waybill\Fuel;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use Brick\Math\BigDecimal;

final readonly class Refueling
{
    public function __construct(
        private \DateTimeImmutable $date,
        private PositiveNumber $quantity,
    ) {
        if (!is_finite((float) $quantity->value())) {
            throw new AppException('Количество топлива должно быть конечным числом.');
        }
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function quantity(): PositiveNumber
    {
        return $this->quantity;
    }

    public function equals(self $other): bool
    {
        return $this->date->format('Y-m-d\TH:i:s.uP') === $other->date->format('Y-m-d\TH:i:s.uP')
            && $this->date->getTimezone()->getName() === $other->date->getTimezone()->getName()
            && BigDecimal::of((string) $this->quantity->value())->isEqualTo((string) $other->quantity->value());
    }
}
