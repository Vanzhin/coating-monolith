<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoCorrosivityCategory;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoDurability;

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

    private function hasStrictlyStronger(ComplianceMatch $candidate): bool
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

    private function isStrictlyStrongerThan(ComplianceMatch $a, ComplianceMatch $b): bool
    {
        // ComplianceStandard пока single-case (ISO_12944), поэтому phpstan считает
        // сравнение всегда-false. Гард намеренный — сравнивать «сильнее» имеет смысл
        // только внутри одного стандарта; заработает при добавлении новых стандартов.
        // @phpstan-ignore-next-line
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
