<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Report;

use App\Shared\Domain\Aggregate\Aggregate;
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
    // null — отчёт без предустановленного типа: композиция из собственного набора блоков (Д4, позже).
    private ?ReportType $type;
    private ReportStatus $status;
    private ?\DateTimeImmutable $reportDate;
    private ?string $actNumber;
    // Ссылки-снимки на справочные сущности: id (связь/аналитика) + title (замороженный снимок).
    private ?string $projectId = null;
    private ?string $projectTitle = null;
    private ?string $customerId = null;
    private ?string $customerTitle = null;
    private ?string $contractorId = null;
    private ?string $contractorTitle = null;
    private ?string $systemId = null;
    private ?string $systemTitle = null;
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
    ) {
        $this->id = $id;
        $this->ownerId = $ownerId;
        $this->type = $type;
        $this->status = ReportStatus::Created;
        $this->reportDate = $reportDate;
        $this->actNumber = $this->normalizeActNumber($actNumber);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Взять в работу: Создан/Отклонён → В работе. */
    public function startWork(\DateTimeImmutable $now): void
    {
        $this->transitionTo(ReportStatus::InWork, $now);
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

    /** Отклонить (ревьюер): На проверке → Отклонён. */
    public function reject(\DateTimeImmutable $now): void
    {
        $this->transitionTo(ReportStatus::Rejected, $now);
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

    public function updateHeader(?\DateTimeImmutable $reportDate, ?string $actNumber, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->reportDate = $reportDate;
        $this->actNumber = $this->normalizeActNumber($actNumber);
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
        $this->projectId = $project?->id;
        $this->projectTitle = $project?->title;
        $this->customerId = $customer?->id;
        $this->customerTitle = $customer?->title;
        $this->contractorId = $contractor?->id;
        $this->contractorTitle = $contractor?->title;
        $this->systemId = $system?->id;
        $this->systemTitle = $system?->title;
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

    private function normalizeActNumber(?string $actNumber): ?string
    {
        $actNumber = null === $actNumber ? null : trim($actNumber);

        return '' === $actNumber ? null : $actNumber;
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

    public function getProject(): ?Reference
    {
        return $this->reference($this->projectId, $this->projectTitle);
    }

    public function getCustomer(): ?Reference
    {
        return $this->reference($this->customerId, $this->customerTitle);
    }

    public function getContractor(): ?Reference
    {
        return $this->reference($this->contractorId, $this->contractorTitle);
    }

    public function getSystem(): ?Reference
    {
        return $this->reference($this->systemId, $this->systemTitle);
    }

    private function reference(?string $id, ?string $title): ?Reference
    {
        return null !== $id && null !== $title ? new Reference($id, $title) : null;
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
