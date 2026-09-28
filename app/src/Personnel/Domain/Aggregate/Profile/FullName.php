<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile;

use App\Shared\Infrastructure\Exception\AppException;

/** ФИО сотрудника. Фамилия и имя обязательны, отчество опционально (не у всех есть). */
final readonly class FullName implements \JsonSerializable
{
    public string $lastName;
    public string $firstName;
    public ?string $middleName;

    public function __construct(string $lastName, string $firstName, ?string $middleName = null)
    {
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $middleName = null !== $middleName ? trim($middleName) : null;

        if ('' === $lastName || '' === $firstName) {
            throw new AppException('Фамилия и имя обязательны.');
        }

        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->middleName = '' !== $middleName ? $middleName : null;
    }

    /** «Фамилия Имя Отчество» (без отчества — «Фамилия Имя»). */
    public function fullString(): string
    {
        return implode(' ', array_filter([$this->lastName, $this->firstName, $this->middleName]));
    }

    /** «Фамилия И. О.» (без отчества — «Фамилия И.»). */
    public function short(): string
    {
        $initials = mb_substr($this->firstName, 0, 1).'.';
        if (null !== $this->middleName) {
            $initials .= ' '.mb_substr($this->middleName, 0, 1).'.';
        }

        return $this->lastName.' '.$initials;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['lastName'] ?? ''),
            (string) ($data['firstName'] ?? ''),
            isset($data['middleName']) ? (string) $data['middleName'] : null,
        );
    }

    /** @return array{lastName: string, firstName: string, middleName: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'lastName' => $this->lastName,
            'firstName' => $this->firstName,
            'middleName' => $this->middleName,
        ];
    }
}
