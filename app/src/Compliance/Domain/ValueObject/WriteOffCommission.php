<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

/**
 * Комиссия по списанию (снимок): представитель отдела ОТ и ПБ + члены комиссии. Хранится на акте в jsonb.
 * Должности/ФИО снимаются с выбранных профилей на момент подписания (не ссылки — потом могут смениться).
 */
final readonly class WriteOffCommission implements \JsonSerializable
{
    /** @var list<WriteOffCommissionMember> */
    public array $members;

    public function __construct(
        public WriteOffCommissionMember $representative,
        WriteOffCommissionMember ...$members,
    ) {
        $this->members = array_values($members);
    }

    /** @return array{representative: array<string, string>, members: list<array<string, string>>} */
    public function jsonSerialize(): array
    {
        return [
            'representative' => $this->representative->jsonSerialize(),
            'members' => array_map(static fn (WriteOffCommissionMember $m): array => $m->jsonSerialize(), $this->members),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $rep */
        $rep = $data['representative'];
        /** @var list<array<string, mixed>> $members */
        $members = $data['members'] ?? [];

        return new self(
            WriteOffCommissionMember::fromArray($rep),
            ...array_map(static fn (array $m): WriteOffCommissionMember => WriteOffCommissionMember::fromArray($m), $members),
        );
    }
}
