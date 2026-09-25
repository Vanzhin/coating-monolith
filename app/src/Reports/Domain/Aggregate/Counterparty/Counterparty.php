<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Counterparty;

use App\Reports\Domain\Aggregate\Counterparty\Specification\CounterpartySpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Контрагент — организация, участвующая в проекте/отчёте. Роль (заказчик, подрядчик, исполнитель,
 * производитель) задаёт НЕ сам контрагент, а ссылка на него из отчёта: один контрагент может
 * фигурировать в разных ролях. Справочник ведёт админ.
 *
 * id передаётся в конструктор (генерация — в Maker/handler), как у Coating.
 */
class Counterparty extends Aggregate
{
    private const MAX_TITLE = 100;
    private const MAX_DESCRIPTION = 750;

    private readonly string $id;
    private string $title;
    private ?string $description;
    /** Нормализованные цифры ИНН (Деплой 2: NOT NULL — у каждого контрагента задан). */
    private string $tin;

    public function __construct(
        string $id,
        string $title,
        private readonly CounterpartySpecification $specification,
        string $tin,
        ?string $description = null,
    ) {
        $this->id = $id;
        $this->setTitle($title);
        $this->setTin($tin);
        $this->setDescription($description);
    }

    public function setTitle(string $title): void
    {
        $title = trim($title);
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new AppException(sprintf('Название контрагента не может быть длиннее %d символов.', self::MAX_TITLE));
        }
        $this->title = $title;
        $this->specification->uniqueTitle->satisfy($this);
    }

    public function setDescription(?string $description): void
    {
        if (null !== $description && mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new AppException(sprintf('Описание контрагента не может быть длиннее %d символов.', self::MAX_DESCRIPTION));
        }
        $this->description = $description;
    }

    public function setTin(string $tin): void
    {
        $this->tin = (new Tin($tin))->value(); // валидность ИНН — в VO; кидает AppException
        $this->specification->uniqueTin->satisfy($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTin(): string
    {
        return $this->tin;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
