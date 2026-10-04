<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * ФИО сотрудника — композиция из частей-VO NamePart. Фамилия и имя обязательны (не-nullable),
 * отчество опционально (не у всех есть). Правило «как выглядит часть имени» (непусто + только буквы) —
 * в NamePart; здесь — только правило композиции (какие части обязательны).
 */
final readonly class FullName implements \JsonSerializable
{
    public function __construct(
        public NamePart $lastName,
        public NamePart $firstName,
        public ?NamePart $middleName = null,
    ) {
    }

    /** Собрать из сырых строк (граница формы/команды): пустое/пробельное отчество → нет отчества. */
    public static function of(string $lastName, string $firstName, ?string $middleName = null): self
    {
        // Обязательность фамилии/имени — правило композиции ФИО: даём единое понятное сообщение
        // раньше, чем пофакторная проверка NamePart («… не может быть пустым»).
        if ('' === trim($lastName) || '' === trim($firstName)) {
            throw new AppException('Фамилия и имя обязательны.');
        }

        $middleName = null !== $middleName ? trim($middleName) : null;

        return new self(
            new NamePart($lastName, 'Фамилия'),
            new NamePart($firstName, 'Имя'),
            null !== $middleName && '' !== $middleName ? new NamePart($middleName, 'Отчество') : null,
        );
    }

    /** «Фамилия Имя Отчество» (без отчества — «Фамилия Имя»). */
    public function fullString(): string
    {
        return implode(' ', array_filter(
            [(string) $this->lastName, (string) $this->firstName, $this->middleName?->value],
            static fn (?string $v): bool => null !== $v,
        ));
    }

    /** «Фамилия И. О.» (без отчества — «Фамилия И.»). */
    public function short(): string
    {
        $initials = mb_substr($this->firstName->value, 0, 1).'.';
        if (null !== $this->middleName) {
            $initials .= ' '.mb_substr($this->middleName->value, 0, 1).'.';
        }

        return $this->lastName->value.' '.$initials;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return self::of(
            (string) ($data['lastName'] ?? ''),
            (string) ($data['firstName'] ?? ''),
            isset($data['middleName']) ? (string) $data['middleName'] : null,
        );
    }

    /** @return array{lastName: string, firstName: string, middleName: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'lastName' => $this->lastName->value,
            'firstName' => $this->firstName->value,
            'middleName' => $this->middleName?->value,
        ];
    }
}
