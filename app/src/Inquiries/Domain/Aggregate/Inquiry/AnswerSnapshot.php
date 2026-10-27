<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Снимок выхода: что подбор вернул на момент ответа (id + подпись) и сколько всего совпало.
 * Это ИСТОРИЧЕСКАЯ запись, а не ключ переиспользования — кэш переиспользует параметры и ищет
 * заново по живому каталогу. Самодостаточен (не требует Coatings для чтения). Хранится jsonb.
 */
final readonly class AnswerSnapshot implements \JsonSerializable
{
    /** @var list<FoundSystem> */
    private array $systems;

    public function __construct(public int $total, FoundSystem ...$systems)
    {
        $this->systems = array_values($systems);
    }

    /** @return list<FoundSystem> */
    public function systems(): array
    {
        return $this->systems;
    }

    /** @return array{total: int, systems: list<array{id: string, label: string}>} */
    public function jsonSerialize(): array
    {
        return [
            'total' => $this->total,
            'systems' => array_map(static fn (FoundSystem $s): array => $s->jsonSerialize(), $this->systems),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $total = isset($data['total']) && is_int($data['total']) ? $data['total'] : 0;
        $rawSystems = isset($data['systems']) && is_array($data['systems']) ? $data['systems'] : [];

        $systems = [];
        foreach ($rawSystems as $raw) {
            if (is_array($raw)) {
                $systems[] = FoundSystem::fromArray($raw);
            }
        }

        return new self($total, ...$systems);
    }
}
