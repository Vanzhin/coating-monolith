<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * Комиссия (снимок): плоский список членов {@see CommissionMember}. Переиспользуемый VO для документов,
 * подписываемых комиссией. Хранится в JSON. Фабрика {@see fromRows} собирает из строк формы (как в отчётах:
 * динамический список, лишняя пустая строка не ломает сохранение).
 */
final readonly class Commission implements \JsonSerializable
{
    /** @var list<CommissionMember> */
    public array $members;

    public function __construct(CommissionMember ...$members)
    {
        $this->members = array_values($members);
    }

    public function isEmpty(): bool
    {
        return [] === $this->members;
    }

    /**
     * Собрать из строк формы (организация/должность/ФИО/дата). Пустые строки (без ФИО) отбрасываем.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function fromRows(array $rows): self
    {
        $members = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ('' === trim((string) ($row['fio'] ?? $row['name'] ?? ''))) {
                continue;
            }
            $members[] = CommissionMember::fromArray($row);
        }

        return new self(...$members);
    }

    /** @param array<string, mixed> $raw Терпит старую форму {representative, members}: representative игнорируем. */
    public static function fromArray(array $raw): self
    {
        /** @var list<array<string, mixed>> $members */
        $members = $raw['members'] ?? [];

        return new self(...array_map(
            static fn (array $m): CommissionMember => CommissionMember::fromArray($m),
            $members,
        ));
    }

    /** @return array{members: list<array<string, string>>} */
    public function jsonSerialize(): array
    {
        return ['members' => array_map(static fn (CommissionMember $m): array => $m->jsonSerialize(), $this->members)];
    }
}
