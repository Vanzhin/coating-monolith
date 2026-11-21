<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class RegistrationNumber
{
    private const string FORMAT = '/\A[abekmhopctyx](?!000)[0-9]{3}[abekmhopctyx]{2}[0-9]{2,3}\z/';

    private string $value;

    public function __construct(string $value)
    {
        $value = strtolower($value);
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw new AppException('Укажите госномер в формате A123BC77 латинскими буквами A, B, E, K, M, H, O, P, C, T, Y, X. Число 000 недопустимо.');
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function formatted(): string
    {
        return strtoupper($this->value);
    }
}
