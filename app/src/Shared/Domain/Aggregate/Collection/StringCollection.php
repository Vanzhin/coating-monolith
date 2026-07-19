<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\Collection;

readonly class StringCollection implements \IteratorAggregate, \JsonSerializable
{
    public array $list;

    public function __construct(string ...$values)
    {
        $this->list = $values;
    }

    public function count(): int
    {
        return count($this->list);
    }

    public function getFirst(): ?string
    {
        return $this->list[0] ?? null;
    }

    public function getList(): array
    {
        return $this->list;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->getList());
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
