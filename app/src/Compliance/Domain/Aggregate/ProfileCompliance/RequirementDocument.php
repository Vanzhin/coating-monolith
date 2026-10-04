<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Личная карточка на (человек × требование) — АКТ выдачи: их несколько во времени (первичка + каждое
 * продление). Черновик (Formed) — маркер «карточка нужна»; «Подписать» (Signed) прикладывает скан и
 * замораживает акт — инвариант по образцу замка отчёта
 * ({@see \App\Reports\Domain\Aggregate\Report\Report::isEditable()}). Подпись включает трекинг сроков
 * требования (см. {@see ProfileCompliance::signDraft()}).
 */
class RequirementDocument
{
    private readonly Uuid $id;
    private ProfileCompliance $profileCompliance;
    private string $requirementId;
    private DocumentStatus $status;
    private ?string $scanFileId = null;
    private ?string $actNumber = null;
    private ?string $responsibleFio = null;
    private ?\DateTimeImmutable $signedAt = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, ProfileCompliance $profileCompliance, string $requirementId, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->requirementId = $requirementId;
        $this->status = DocumentStatus::Formed;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function isDraft(): bool
    {
        return DocumentStatus::Formed === $this->status;
    }

    /** Приложить подписанный скан → Signed (замок). № карточки и ответственное лицо обязательны. Повторно/поверх — запрещено. */
    public function markSigned(string $scanFileId, string $actNumber, string $responsibleFio, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        if ('' === trim($actNumber)) {
            throw new AppException('Укажите № карточки.');
        }
        if ('' === trim($responsibleFio)) {
            throw new AppException('Укажите ответственное лицо за ведение карточки.');
        }
        $this->status = DocumentStatus::Signed;
        $this->scanFileId = $scanFileId;
        $this->actNumber = trim($actNumber);
        $this->responsibleFio = trim($responsibleFio);
        $this->signedAt = $now;
        $this->updatedAt = $now;
    }

    public function actNumber(): ?string
    {
        return $this->actNumber;
    }

    public function responsibleFio(): ?string
    {
        return $this->responsibleFio;
    }

    public function isEditable(): bool
    {
        return DocumentStatus::Signed !== $this->status;
    }

    public function isSigned(): bool
    {
        return DocumentStatus::Signed === $this->status;
    }

    /** Заморозка после подписи — правки/удаление запрещены всем (как утверждённый отчёт). */
    public function assertMutable(): void
    {
        if (!$this->isEditable()) {
            throw new AppException('Карточка подписана — её нельзя изменять или удалить.');
        }
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function profileCompliance(): ProfileCompliance
    {
        return $this->profileCompliance;
    }

    public function requirementId(): string
    {
        return $this->requirementId;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function scanFileId(): ?string
    {
        return $this->scanFileId;
    }

    public function signedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
