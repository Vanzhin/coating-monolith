<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

/** Член комиссии по списанию — снимок должности и ФИО (на момент подписания акта). */
final readonly class WriteOffCommissionMember implements \JsonSerializable
{
    public function __construct(
        public string $position,
        public string $fio,
    ) {
    }

    /** @return array{position: string, fio: string} */
    public function jsonSerialize(): array
    {
        return ['position' => $this->position, 'fio' => $this->fio];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['position'], (string) $data['fio']);
    }
}
