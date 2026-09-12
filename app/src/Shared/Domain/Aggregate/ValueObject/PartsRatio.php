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
    /** @var list<PositiveNumber> */
    private array $parts;

    public function __construct(PositiveNumber ...$parts)
    {
        if (count($parts) < 2) {
            throw new AppException('Соотношение должно содержать минимум два компонента.');
        }

        foreach ($parts as $part) {
            // Положительность уже гарантирована типом PositiveNumber. Здесь — своё правило
            // соотношения: целое или максимум два знака после запятой (точнее не задают).
            if (abs($part->value() * 100 - round($part->value() * 100)) > 1e-9) {
                throw new AppException('Доля компонента может иметь не более двух знаков после запятой.');
            }
        }
        $this->parts = array_values($parts);
    }

    /** @return list<float> */
    public function getParts(): array
    {
        return array_map(static fn (PositiveNumber $part): float => (float) $part->value(), $this->parts);
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

        $scale = $amount / $this->parts[$index]->value();

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

        $scale = $total / array_sum($this->getParts());

        return $this->doseAtScale($scale);
    }

    private function doseAtScale(float $scale): MixDose
    {
        return new MixDose(...array_map(static fn (float $part): float => $part * $scale, $this->getParts()));
    }
}
