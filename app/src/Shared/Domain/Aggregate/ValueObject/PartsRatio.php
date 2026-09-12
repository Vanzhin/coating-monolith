<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Соотношение смешивания в частях по одной базе измерения (объёмной ИЛИ массовой).
 * Например 3:1 — три части основы к одной части отвердителя, 4:1:0.5 — три компонента.
 * Первая доля по конвенции — основа, дальше отвердитель/добавки.
 *
 * VO не хранит единицы измерения: доли безразмерны и скейлят то количество, что дал
 * вызывающий (л/мл для объёма, кг/г для массы). Считает дозировки рабочей смеси:
 * от отмеренного компонента (fromComponent/fromBase/fromHardener) или от целевого
 * объёма готовой смеси (fromTotal).
 */
final readonly class PartsRatio
{
    /** @var list<float> */
    private array $parts;

    public function __construct(float ...$parts)
    {
        if (count($parts) < 2) {
            throw new AppException('Соотношение должно содержать минимум два компонента.');
        }
        foreach ($parts as $part) {
            if ($part <= 0) {
                throw new AppException('Доля компонента должна быть положительной.');
            }
        }
        $this->parts = array_values($parts);
    }

    /** @return list<float> */
    public function getParts(): array
    {
        return $this->parts;
    }

    public function count(): int
    {
        return count($this->parts);
    }

    /**
     * Дозировки всех компонентов, если компонента №$index отмерено $amount.
     * Общий движок, поверх него — именованные fromBase/fromHardener.
     */
    public function fromComponent(int $index, float $amount): MixDose
    {
        if ($index < 0 || $index >= count($this->parts)) {
            throw new AppException('Компонента с таким номером в соотношении нет.');
        }
        if ($amount <= 0) {
            throw new AppException('Количество должно быть положительным.');
        }

        $scale = $amount / $this->parts[$index];

        return $this->doseAtScale($scale);
    }

    /** Дозировки, если основы отмерено $amount. */
    public function fromBase(float $amount): MixDose
    {
        return $this->fromComponent(0, $amount);
    }

    /**
     * Дозировки, если отвердителя отмерено $amount. Однозначно только для двухкомпонентного
     * соотношения (основа + один отвердитель); при большем числе компонентов «отвердитель»
     * неоднозначен — используйте fromComponent с явным номером.
     */
    public function fromHardener(float $amount): MixDose
    {
        if (2 !== count($this->parts)) {
            throw new AppException('В соотношении не два компонента — уточните, от какого считать.');
        }

        return $this->fromComponent(1, $amount);
    }

    /** Дозировки, если нужно $total готовой смеси (сумма всех компонентов). */
    public function fromTotal(float $total): MixDose
    {
        if ($total <= 0) {
            throw new AppException('Количество должно быть положительным.');
        }

        $scale = $total / array_sum($this->parts);

        return $this->doseAtScale($scale);
    }

    private function doseAtScale(float $scale): MixDose
    {
        return new MixDose(...array_map(static fn (float $part): float => $part * $scale, $this->parts));
    }
}
