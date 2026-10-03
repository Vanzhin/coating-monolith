<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Член комиссии (снимок, свободный ввод): ФИО обязателен, организация/должность/дата — опциональны.
 * Переиспользуемый VO для документов, подписываемых комиссией (акты списания, отчёты). Хранится в JSON.
 */
final readonly class CommissionMember implements \JsonSerializable
{
    public function __construct(
        public string $fio,
        public string $organization = '',
        public string $position = '',
        public string $date = '',
    ) {
        if ('' === trim($fio)) {
            throw new AppException('Член комиссии: укажите ФИО.');
        }
    }

    /** @param array<string, mixed> $raw Терпит обе формы ключа ФИО: name (форма/отчёты) и fio (снимок). */
    public static function fromArray(array $raw): self
    {
        return new self(
            trim((string) ($raw['fio'] ?? $raw['name'] ?? '')),
            trim((string) ($raw['organization'] ?? '')),
            trim((string) ($raw['position'] ?? '')),
            trim((string) ($raw['date'] ?? '')),
        );
    }

    /** @return array{organization: string, position: string, fio: string, date: string} */
    public function jsonSerialize(): array
    {
        return [
            'organization' => $this->organization,
            'position' => $this->position,
            'fio' => $this->fio,
            'date' => $this->date,
        ];
    }
}
