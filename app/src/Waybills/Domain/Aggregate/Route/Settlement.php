<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class Settlement implements \JsonSerializable
{
    public string $name;

    public function __construct(string $name, public ?SettlementType $type = null)
    {
        $name = trim($name);
        if ('' === $name) {
            throw new AppException('Укажите название населённого пункта.');
        }
        $this->name = $name;
    }

    public function equals(?self $other): bool
    {
        return null !== $other && $this->name === $other->name && $this->type === $other->type;
    }

    public function toString(): string
    {
        return null === $this->type ? $this->name : $this->type->abbreviation().' '.$this->name;
    }

    /** @return array{name: string, type: ?string} */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'type' => $this->type?->value];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (null === $data || '' === $data) {
            return null;
        }
        if (is_string($data)) {
            return new self($data);
        }
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            throw new AppException('Некорректные данные населённого пункта.');
        }
        $type = $data['type'] ?? null;
        if (null !== $type && (!is_string($type) || null === SettlementType::tryFrom($type))) {
            throw new AppException('Некорректный тип населённого пункта.');
        }

        return new self($data['name'], null === $type ? null : SettlementType::from($type));
    }
}
