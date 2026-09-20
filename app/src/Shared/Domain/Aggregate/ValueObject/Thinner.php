<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

/**
 * Разбавитель слоя: название + № партии + количество (Percent, [0;100] — по объёму). Инвариант
 * процента — в Percent; название/партия — свободный текст. Композиция, без своих правил.
 */
final readonly class Thinner implements \JsonSerializable
{
    public function __construct(
        private string $name,
        private string $batch,
        private Percent $percent,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBatch(): string
    {
        return $this->batch;
    }

    public function getPercent(): int|float
    {
        return $this->percent->value();
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            trim((string) ($raw['name'] ?? '')),
            trim((string) ($raw['batch'] ?? '')),
            new Percent((float) ($raw['percent'] ?? 0)),
        );
    }

    /** @return array{name: string, batch: string, percent: int|float} */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'batch' => $this->batch, 'percent' => $this->getPercent()];
    }
}
