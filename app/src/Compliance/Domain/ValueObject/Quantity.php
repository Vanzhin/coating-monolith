<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/** Количество СИЗ: положительное число + единица измерения. */
final readonly class Quantity implements \JsonSerializable
{
    public function __construct(public float $amount, public Unit $unit)
    {
        if ($amount <= 0) {
            throw new AppException('Количество должно быть положительным.');
        }
    }

    public function label(): string
    {
        $amount = 0.0 === fmod($this->amount, 1.0) ? (string) (int) $this->amount : (string) $this->amount;

        return $amount.' '.$this->unit->title();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((float) $data['amount'], Unit::from((string) $data['unit']));
    }

    /** @return array{amount: float, unit: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'unit' => $this->unit->value];
    }
}
