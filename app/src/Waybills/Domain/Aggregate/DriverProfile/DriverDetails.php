<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class DriverDetails
{
    public string $fullName;
    public DriverLicense $licenseNumber;
    public Snils $snils;

    public function __construct(
        string $fullName,
        string $licenseNumber,
        public \DateTimeImmutable $licenseIssuedAt,
        string $snils,
    ) {
        $this->fullName = self::requireText($fullName, 'Укажите ФИО водителя.');
        $this->licenseNumber = new DriverLicense($licenseNumber);
        $this->snils = new Snils($snils);
    }

    private static function requireText(string $value, string $message): string
    {
        $value = trim($value);
        if (1 === preg_match('/\A[\s\p{Z}]*\z/u', $value)) {
            throw new AppException($message);
        }

        return $value;
    }
}
