<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Database\DBAL;

use App\Shared\Infrastructure\Database\DBAL\ExactDateTimeType;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class DriverDetailsType extends JsonType
{
    public function convertToPHPValue($value, AbstractPlatform $platform): ?DriverDetails
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }
        if (!is_array($data) || !isset($data['fullName'], $data['licenseNumber'], $data['licenseIssuedAt'], $data['snils'])
            || !is_string($data['fullName']) || !is_string($data['licenseNumber']) || !is_string($data['licenseIssuedAt']) || !is_string($data['snils'])) {
            throw new \UnexpectedValueException('Invalid driver details.');
        }

        if (str_contains($data['licenseIssuedAt'], '|')) {
            $issuedAt = ExactDateTimeType::parse($data['licenseIssuedAt']);
        } else {
            // Записи профилей прежнего формата содержат offset, но не имя зоны.
            $issuedAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $data['licenseIssuedAt']);
            if (false === $issuedAt || $issuedAt->format('Y-m-d\TH:i:s.uP') !== $data['licenseIssuedAt']) {
                throw new \UnexpectedValueException('Invalid driver license date.');
            }
        }

        return new DriverDetails($data['fullName'], $data['licenseNumber'], $issuedAt, $data['snils']);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof DriverDetails) {
            throw new \LogicException('Expected DriverDetails, got '.get_debug_type($value));
        }

        return parent::convertToDatabaseValue([
            'fullName' => $value->fullName,
            'licenseNumber' => $value->licenseNumber->value(),
            'licenseIssuedAt' => ExactDateTimeType::format($value->licenseIssuedAt),
            'snils' => $value->snils->value(),
        ], $platform);
    }
}
