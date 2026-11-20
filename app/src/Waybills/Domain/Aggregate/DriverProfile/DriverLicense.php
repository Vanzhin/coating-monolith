<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class DriverLicense
{
    private const string FORMAT = '/\A(?:[0-9]{4}|[0-9]{2}[ABEKMHOPCTYX]{2})[0-9]{6}\z/';
    private const array CYRILLIC_TO_LATIN = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K',
        'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P',
        'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
    ];

    private string $value;

    public function __construct(string $value)
    {
        $value = preg_replace('/[\s\p{Z}-]+/u', '', $value);
        if (null !== $value) {
            $value = strtr(mb_strtoupper($value), self::CYRILLIC_TO_LATIN);
        }

        if (null === $value || 1 !== preg_match(self::FORMAT, $value)) {
            throw new AppException('Укажите серию и номер водительского удостоверения в формате 99 12 123456 или 77 AA 123456.');
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function formatted(): string
    {
        return sprintf('%s %s %s', substr($this->value, 0, 2), substr($this->value, 2, 2), substr($this->value, 4, 6));
    }
}
