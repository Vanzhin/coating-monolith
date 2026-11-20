<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class Snils
{
    private const int LAST_NUMBER_WITHOUT_CHECKSUM = 1001998;

    private string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (1 !== preg_match('/\A[0-9]{3}-?[0-9]{3}-?[0-9]{3}[ -]?[0-9]{2}\z/', $value)) {
            throw new AppException('Укажите СНИЛС из 11 цифр, например 112-233-445 95.');
        }

        $digits = str_replace(['-', ' '], '', $value);
        if (!self::hasValidChecksum($digits)) {
            throw new AppException('Неверная контрольная сумма СНИЛС.');
        }

        $this->value = $digits;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function formatted(): string
    {
        return sprintf('%s-%s-%s %s', substr($this->value, 0, 3), substr($this->value, 3, 3), substr($this->value, 6, 3), substr($this->value, 9, 2));
    }

    private static function hasValidChecksum(string $digits): bool
    {
        $number = substr($digits, 0, 9);
        if ((int) $number <= self::LAST_NUMBER_WITHOUT_CHECKSUM) {
            return true;
        }

        $sum = 0;
        for ($index = 0; $index < 9; ++$index) {
            $sum += (int) $number[$index] * (9 - $index);
        }
        $checksum = $sum < 100 ? $sum : $sum % 101;
        if (100 === $checksum) {
            $checksum = 0;
        }

        return $checksum === (int) substr($digits, 9, 2);
    }
}
