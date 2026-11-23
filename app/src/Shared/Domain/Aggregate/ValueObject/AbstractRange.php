<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/** @template T */
abstract readonly class AbstractRange
{
    /** @var T */
    protected mixed $start;

    /** @var T */
    protected mixed $end;

    /**
     * @param T $start
     * @param T $end
     */
    protected function __construct(mixed $start, mixed $end)
    {
        if ($start > $end) {
            throw new AppException('Максимальное значение не может быть менее минимального.');
        }

        $this->start = $start;
        $this->end = $end;
    }

    /** @return T */
    public function start(): mixed
    {
        return $this->start;
    }

    /** @return T */
    public function end(): mixed
    {
        return $this->end;
    }

    /** @param T $value */
    public function contains(mixed $value): bool
    {
        return $value >= $this->start && $value <= $this->end;
    }
}
