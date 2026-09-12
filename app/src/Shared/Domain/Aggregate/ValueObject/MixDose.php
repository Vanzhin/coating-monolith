<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

/**
 * Результат расчёта рабочей смеси: сколько каждого компонента отмерить.
 * Индекс 0 — основа, дальше отвердитель/добавки в порядке долей соотношения.
 * Числа сырые (как посчитала пропорция); округление — забота UI.
 */
final readonly class MixDose
{
    /** @var list<float> */
    private array $amounts;

    public function __construct(float ...$amounts)
    {
        $this->amounts = array_values($amounts);
    }

    /** @return list<float> */
    public function getAmounts(): array
    {
        return $this->amounts;
    }

    /** Количество основы (первый компонент). */
    public function getBase(): float
    {
        return $this->amounts[0];
    }

    /**
     * Количества всего, кроме основы: отвердитель и добавки.
     *
     * @return list<float>
     */
    public function getAdditions(): array
    {
        return array_values(array_slice($this->amounts, 1));
    }

    /** Итоговый объём/масса готовой смеси. */
    public function getTotal(): float
    {
        return array_sum($this->amounts);
    }
}
