<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Project;

use App\Reports\Domain\Aggregate\Counterparty\Counterparty;
use App\Reports\Domain\Aggregate\Project\Specification\ProjectSpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Проект — объект работ, на котором ведутся отчёты. Принадлежит одному контрагенту-заказчику
 * (много проектов у одного заказчика). Справочник ведёт админ. id передаётся в конструктор.
 */
class Project extends Aggregate
{
    private const MAX_TITLE = 100;
    private const MAX_DESCRIPTION = 750;

    private readonly string $id;
    private string $title;
    private ?string $description;
    private Counterparty $counterparty;

    public function __construct(
        string $id,
        string $title,
        private readonly ProjectSpecification $specification,
        Counterparty $counterparty,
        ?string $description = null,
    ) {
        $this->id = $id;
        $this->counterparty = $counterparty;
        $this->setTitle($title);
        $this->setDescription($description);
    }

    public function setTitle(string $title): void
    {
        $title = trim($title);
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new AppException(sprintf('Название проекта не может быть длиннее %d символов.', self::MAX_TITLE));
        }
        $this->title = $title;
        $this->specification->uniqueTitle->satisfy($this);
    }

    public function setDescription(?string $description): void
    {
        if (null !== $description && mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new AppException(sprintf('Описание проекта не может быть длиннее %d символов.', self::MAX_DESCRIPTION));
        }
        $this->description = $description;
    }

    public function setCounterparty(Counterparty $counterparty): void
    {
        $this->counterparty = $counterparty;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCounterparty(): Counterparty
    {
        return $this->counterparty;
    }
}
