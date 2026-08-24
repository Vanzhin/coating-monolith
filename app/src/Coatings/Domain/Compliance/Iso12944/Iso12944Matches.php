<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

final class Iso12944Matches implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var list<Iso12944Match> */
    private array $items = [];

    public function add(Iso12944Match $m): void
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
     * @return list<Iso12944Match>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Оставить только сильнейшие соответствия: пара (category, durability) убирается,
     * если в результате есть другая пара той же семьи категорий с не меньшей категорией
     * и не меньшей долговечностью (хотя бы одно из них строго больше). Семьи разные для
     * атмосферных (C1..CX) и погружных (Im1..Im3) категорий. Точные дубли схлопываются.
     * Порядок первого появления оставшихся элементов сохраняется.
     */
    public function strongestOnly(): self
    {
        $result = new self();
        $seenKeys = [];
        foreach ($this->items as $candidate) {
            $key = $candidate->standard->value.'|'.$candidate->category.'|'.$candidate->durability;
            if (isset($seenKeys[$key])) {
                continue;
            }
            if ($this->hasStrictlyStronger($candidate)) {
                continue;
            }
            $seenKeys[$key] = true;
            $result->items[] = $candidate;
        }

        return $result;
    }

    private function hasStrictlyStronger(Iso12944Match $candidate): bool
    {
        foreach ($this->items as $other) {
            if ($other === $candidate) {
                continue;
            }
            if ($this->isStrictlyStrongerThan($other, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isStrictlyStrongerThan(Iso12944Match $a, Iso12944Match $b): bool
    {
        // Сравнивать «сильнее» имеет смысл только внутри одного стандарта.
        if ($a->standard !== $b->standard) {
            return false;
        }
        $catA = IsoCorrosivityCategory::tryFrom($a->category);
        $catB = IsoCorrosivityCategory::tryFrom($b->category);
        if (null === $catA || null === $catB) {
            return false;
        }
        if ($catA->family() !== $catB->family()) {
            return false;
        }
        $durA = IsoDurability::tryFrom($a->durability);
        $durB = IsoDurability::tryFrom($b->durability);
        if (null === $durA || null === $durB) {
            return false;
        }
        $catCmp = $catA->rank() - $catB->rank();
        $durCmp = $durA->rank() - $durB->rank();

        return $catCmp >= 0 && $durCmp >= 0 && ($catCmp > 0 || $durCmp > 0);
    }
}
