<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service;

final readonly class RouteFillSolution
{
    /**
     * @param list<RouteFillOption> $options
     * @param array<int, int>       $usesByDirection
     */
    private function __construct(
        private array $options,
        private array $usesByDirection,
        private int $deviation,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], 0);
    }

    public function withOption(RouteFillOption $option): self
    {
        $options = $this->options;
        $options[] = $option;
        $usesByDirection = $this->usesByDirection;
        $usesByDirection[$option->direction] = ($usesByDirection[$option->direction] ?? 0) + 1;

        return new self($options, $usesByDirection, $this->deviation + $option->deviation());
    }

    /** @return list<RouteFillOption> */
    public function options(): array
    {
        return $this->options;
    }

    public function coverage(): int
    {
        return count($this->usesByDirection);
    }

    public function coverageKey(): string
    {
        $directions = array_keys($this->usesByDirection);
        sort($directions);

        return implode(',', $directions);
    }

    public function deviation(): int
    {
        return $this->deviation;
    }

    public function pairCount(): int
    {
        return count($this->options);
    }

    public function imbalance(): int
    {
        if ([] === $this->usesByDirection) {
            return 0;
        }

        return max($this->usesByDirection) - min($this->usesByDirection);
    }
}
