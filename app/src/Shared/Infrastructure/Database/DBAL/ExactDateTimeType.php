<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\DBAL;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;

/** Сохраняет момент, микросекунды и исходную часовую зону. */
final class ExactDateTimeType extends TextType
{
    private const string FORMAT = 'Y-m-d\TH:i:s.uP';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Ожидается неизменяемая дата и время.');
        }

        return self::format($value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        $value = parent::convertToPHPValue($value, $platform);
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Ожидается дата и время с часовой зоной.');
        }

        return self::parse($value);
    }

    public static function format(\DateTimeImmutable $value): string
    {
        return $value->format(self::FORMAT).'|'.$value->getTimezone()->getName();
    }

    public static function parse(string $value): \DateTimeImmutable
    {
        $parts = explode('|', $value);
        if (2 !== count($parts)) {
            throw new \UnexpectedValueException('Некорректный формат даты и времени с часовой зоной.');
        }
        $date = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, $parts[0]);
        if (false === $date) {
            throw new \UnexpectedValueException('Некорректная дата и время.');
        }
        try {
            $date = $date->setTimezone(new \DateTimeZone($parts[1]));
        } catch (\Exception $exception) {
            throw new \UnexpectedValueException('Некорректная часовая зона.', previous: $exception);
        }
        if (self::format($date) !== $value) {
            throw new \UnexpectedValueException('Дата и время не соответствуют часовой зоне.');
        }

        return $date;
    }
}
