<?php

declare(strict_types=1);

namespace App\Personnel\Domain\ValueObject;

/**
 * Снимок ссылки профиля сотрудника на справочную сущность (должность/организация/отдел):
 * id для связи+аналитики, title — снимок названия на момент выбора (историчность: профиль
 * не переписывается при переименовании/удалении сущности). Всегда пара {id, title}; «нет ссылки» —
 * сам Reference null.
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
