<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Counterparty;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * ИНН (идентификационный номер налогоплательщика): 10 цифр (юрлицо) или 12 (физлицо/ИП), с проверкой
 * контрольной суммы. Правило «ИНН валиден» живёт здесь — в самом узком владельце (VO), а не в контроллере
 * или команде. Уникальность ИНН — правило про несколько агрегатов, поэтому оно НЕ тут, а в доменной спеке
 * (UniqueTinCounterpartySpecification). VO хранит нормализованные (обрезанные по краям) цифры.
 */
final readonly class Tin implements \JsonSerializable
{
    private string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if (1 === preg_match('/\D/', $value)) {
            throw new AppException('ИНН может состоять только из цифр.');
        }

        $length = \strlen($value);
        if (10 !== $length && 12 !== $length) {
            throw new AppException('ИНН должен состоять из 10 или 12 цифр.');
        }

        if (!$this->checksumValid($value, $length)) {
            throw new AppException('Неверная контрольная сумма ИНН.');
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    private function checksumValid(string $inn, int $length): bool
    {
        if (10 === $length) {
            return $this->checkDigit($inn, [2, 4, 10, 3, 5, 9, 4, 6, 8]) === (int) $inn[9];
        }

        $eleventh = $this->checkDigit($inn, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
        $twelfth = $this->checkDigit($inn, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);

        return $eleventh === (int) $inn[10] && $twelfth === (int) $inn[11];
    }

    /**
     * @param list<int> $coefficients
     */
    private function checkDigit(string $inn, array $coefficients): int
    {
        $sum = 0;
        foreach ($coefficients as $i => $k) {
            $sum += $k * (int) $inn[$i];
        }

        return $sum % 11 % 10;
    }
}
