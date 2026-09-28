<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Position;

use App\Personnel\Domain\Aggregate\Position\Specification\PositionSpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Должность — справочник, которым помечается профиль сотрудника (Personnel/Profile).
 * Создаётся налету (quick-create) из формы профиля.
 *
 * id передаётся в конструктор (генерация — в Maker/handler), как у Coating/Counterparty.
 */
class Position extends Aggregate
{
    private const MAX_TITLE = 150;

    private readonly string $id;
    private string $title;

    public function __construct(
        string $id,
        string $title,
        private readonly PositionSpecification $specification,
    ) {
        $this->id = $id;
        $this->setTitle($title);
    }

    public function rename(string $title): void
    {
        $this->setTitle($title);
    }

    private function setTitle(string $title): void
    {
        $title = trim($title);
        if ('' === $title) {
            throw new AppException('Название должности обязательно.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new AppException(sprintf('Название должности не может быть длиннее %d символов.', self::MAX_TITLE));
        }
        $this->title = $title;
        $this->specification->uniqueTitle->satisfy($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
