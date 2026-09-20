<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Report;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Отчёт — корень домена полевых отчётов. Общие свойства (владелец, вид, статус, дата, номер акта) —
 * поля агрегата; блок-специфика — в content (jsonb, схема/валидация — реестр блоков, отдельно).
 *
 * Статус НЕ задаётся извне произвольно: он СЛЕДУЕТ из доменных действий (startWork/submitForReview/
 * approve/reject). Переход валидируется приватным transitionTo по логической цепочке ReportStatus;
 * «Утверждён» — замороженное терминальное состояние. КТО вправе выполнить действие (владелец
 * отправляет, ревьюер утверждает) — авторизация в Application. id передаётся в конструктор.
 */
class Report extends Aggregate
{
    private readonly Uuid $id;
    // Ссылки на пользователя — ulid-строка (User.id = ulid, length 26), не Uuid.
    private string $ownerId;
    private ?string $reviewerId = null;
    private ?string $rejectionReason = null;
    // null — отчёт без предустановленного типа: композиция из собственного набора блоков (Д4, позже).
    private ?ReportType $type;
    private ReportStatus $status;
    private ?\DateTimeImmutable $reportDate;
    private ?string $actNumber;
    // Адрес объекта (текст) и период проведения работ (интервал дат) — реквизиты-шапка.
    private ?string $address = null;
    private ?DateTimeInterval $workPeriod = null;
    // Ссылки-снимки на справочные сущности: VO {id (связь/аналитика), title (замороженный снимок)}.
    // null-поле = ссылки нет. Хранятся как JSON (DBAL reports_reference).
    private ?Reference $project = null;
    private ?Reference $customer = null;
    private ?Reference $contractor = null;
    private ?Reference $system = null;
    /** @var array<string, mixed> данные по блокам; схема/валидация — реестр блоков (позже) */
    private array $content = [];
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    public function __construct(
        Uuid $id,
        string $ownerId,
        ?ReportType $type,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $reportDate = null,
        ?string $actNumber = null,
        ?string $address = null,
        ?DateTimeInterval $workPeriod = null,
    ) {
        $this->id = $id;
        $this->ownerId = $ownerId;
        $this->type = $type;
        $this->status = ReportStatus::Created;
        $this->reportDate = $reportDate;
        $this->actNumber = $this->normalizeActNumber($actNumber);
        $this->address = $this->normalizeText($address);
        $this->workPeriod = $workPeriod;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Взять в работу: Создан/Отклонён → В работе. Причина прошлого отклонения снимается. */
    public function startWork(\DateTimeImmutable $now): void
    {
        $this->transitionTo(ReportStatus::InWork, $now);
        $this->rejectionReason = null;
    }

    /** Отправить на проверку: В работе → На проверке. */
    public function submitForReview(\DateTimeImmutable $now): void
    {
        $this->transitionTo(ReportStatus::UnderReview, $now);
    }

    /** Утвердить (ревьюер): На проверке → Утверждён (заморозка). */
    public function approve(\DateTimeImmutable $now): void
    {
        $this->transitionTo(ReportStatus::Approved, $now);
    }

    /** Отклонить (ревьюер): На проверке → Отклонён, с причиной для автора. */
    public function reject(?string $reason, \DateTimeImmutable $now): void
    {
        $this->transitionTo(ReportStatus::Rejected, $now);
        $this->rejectionReason = $reason;
    }

    private function transitionTo(ReportStatus $to, \DateTimeImmutable $now): void
    {
        if ($this->status === $to) {
            return;
        }
        if (!$this->status->canTransitionTo($to)) {
            throw new AppException(sprintf('Недопустимый переход статуса: «%s» → «%s».', $this->status->label(), $to->label()));
        }
        $this->status = $to;
        $this->updatedAt = $now;
    }

    public function assignReviewer(?string $reviewerId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->reviewerId = $reviewerId;
        $this->updatedAt = $now;
    }

    public function updateHeader(
        ?\DateTimeImmutable $reportDate,
        ?string $actNumber,
        ?string $address,
        ?DateTimeInterval $workPeriod,
        \DateTimeImmutable $now,
    ): void {
        $this->assertMutable();
        $this->reportDate = $reportDate;
        $this->actNumber = $this->normalizeActNumber($actNumber);
        $this->address = $this->normalizeText($address);
        $this->workPeriod = $workPeriod;
        $this->updatedAt = $now;
    }

    /** Проставить ссылки-снимки (резолв id→сущность делает Application). null — ссылка снята. */
    public function applyReferences(
        ?Reference $project,
        ?Reference $customer,
        ?Reference $contractor,
        ?Reference $system,
        \DateTimeImmutable $now,
    ): void {
        $this->assertMutable();
        $this->project = $project;
        $this->customer = $customer;
        $this->contractor = $contractor;
        $this->system = $system;
        $this->updatedAt = $now;
    }

    /**
     * Заменить содержимое блоков целиком. Валидация против схемы блоков — в Application
     * (ReportContentValidator) до вызова; агрегат лишь бережёт заморозку.
     *
     * @param array<string, mixed> $content
     */
    public function replaceContent(array $content, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->content = $content;
        $this->updatedAt = $now;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    /** Утверждённый отчёт менять нельзя. */
    public function assertMutable(): void
    {
        if ($this->status->isFrozen()) {
            throw new AppException('Утверждённый отчёт нельзя изменять.');
        }
    }

    /** Утверждённый отчёт иммутабелен — удалять нельзя. */
    public function assertDeletable(): void
    {
        if ($this->status->isFrozen()) {
            throw new AppException('Утверждённый отчёт нельзя удалить.');
        }
    }

    private function normalizeActNumber(?string $actNumber): ?string
    {
        return $this->normalizeText($actNumber);
    }

    private function normalizeText(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getReviewerId(): ?string
    {
        return $this->reviewerId;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function getType(): ?ReportType
    {
        return $this->type;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function getReportDate(): ?\DateTimeImmutable
    {
        return $this->reportDate;
    }

    public function getActNumber(): ?string
    {
        return $this->actNumber;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getWorkPeriod(): ?DateTimeInterval
    {
        return $this->workPeriod;
    }

    public function getProject(): ?Reference
    {
        return $this->project;
    }

    public function getCustomer(): ?Reference
    {
        return $this->customer;
    }

    public function getContractor(): ?Reference
    {
        return $this->contractor;
    }

    public function getSystem(): ?Reference
    {
        return $this->system;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Оптимистичная блокировка (Doctrine @Version) — пригодится для синка (Д3). */
    public function getVersion(): int
    {
        return $this->version;
    }
}
