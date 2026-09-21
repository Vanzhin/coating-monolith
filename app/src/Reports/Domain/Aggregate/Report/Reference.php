<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Report;

/**
 * Снимок ссылки отчёта на справочную сущность (проект/контрагент/система): id для связи+аналитики,
 * title — снимок названия на момент выбора (историчность + офлайн-целостность: отчёт не переписывается
 * при переименовании/удалении сущности). Всегда пара {id, title}; «нет ссылки» — сам Reference null.
 */
final readonly class Reference implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self((string) ($raw['id'] ?? ''), (string) ($raw['title'] ?? ''));
    }

    /** @return array{id: string, title: string} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
