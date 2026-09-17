<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

final readonly class ChangeSet implements \JsonSerializable
{
    /** @var list<FieldChange> */
    private array $changes;

    public function __construct(FieldChange ...$changes)
    {
        $this->changes = array_values($changes);
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes;
    }

    /** @return list<FieldChange> */
    public function all(): array
    {
        return $this->changes;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn (FieldChange $c): array => $c->jsonSerialize(), $this->changes);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function fromArray(array $rows): self
    {
        return new self(...array_map(FieldChange::fromArray(...), $rows));
    }
}
