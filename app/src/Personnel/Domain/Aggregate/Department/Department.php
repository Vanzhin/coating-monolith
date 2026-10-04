<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Department;

use App\Personnel\Domain\Service\DepartmentTreePolicy;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Отдел — иерархический справочник (self-parent дерево), привязан к компании (Reports/Counterparty,
 * по строковому id). На узле — начальник (headUserUlid), ответственный по умолчанию
 * за сотрудников узла (резолв цепочки — Deploy 4).
 *
 * id передаётся в конструктор (генерация — в Maker/handler), как Position/Coating.
 */
class Department extends Aggregate
{
    private const MAX_TITLE = 150;

    private readonly string $id;
    private string $title;
    private readonly string $companyId;
    private ?string $parentId;
    private ?string $headUserUlid;

    public function __construct(
        string $id,
        string $title,
        string $companyId,
        ?string $parentId,
        ?string $headUserUlid,
        DepartmentTreePolicy $policy,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->setTitle($title);
        if (null !== $parentId) {
            $policy->assertParentValid($this, $parentId);
        }
        $this->parentId = $parentId;
        $this->assignHead($headUserUlid);
    }

    public function rename(string $title): void
    {
        $this->setTitle($title);
    }

    public function moveTo(?string $parentId, DepartmentTreePolicy $policy): void
    {
        if (null !== $parentId) {
            $policy->assertParentValid($this, $parentId);
        }
        $this->parentId = $parentId;
    }

    public function assignHead(?string $userUlid): void
    {
        $userUlid = null !== $userUlid ? trim($userUlid) : null;
        $this->headUserUlid = ('' === $userUlid) ? null : $userUlid;
    }

    private function setTitle(string $title): void
    {
        $title = trim($title);
        if ('' === $title) {
            throw new AppException('Название отдела обязательно.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new AppException(sprintf('Название отдела не может быть длиннее %d символов.', self::MAX_TITLE));
        }
        $this->title = $title;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    public function getHeadUserUlid(): ?string
    {
        return $this->headUserUlid;
    }
}
