<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

final class ComplianceMatches implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var list<ComplianceMatch> */
    private array $items = [];

    public function add(ComplianceMatch $m): void
    {
        $this->items[] = $m;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    /**
     * @return list<array{standard: string, category: string, durability: string}>
     */
    public function jsonSerialize(): array
    {
        return array_map(fn ($m) => $m->jsonSerialize(), $this->items);
    }

    /**
     * @return list<ComplianceMatch>
     */
    public function toArray(): array
    {
        return $this->items;
    }
}
