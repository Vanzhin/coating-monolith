<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

/**
 * Акт списания СИЗ (на человека, по требованию) — заголовок-документ, зеркало акта получения. Состав —
 * порции {@see WriteOffItem} (сколько какого факта списано + причина). Цикл {@see DocumentStatus}: Черновик
 * (кладём/убираем порции, задаём причины) → Подписан (комиссия + №/дата + скан, заморожен). Эффект (гашение
 * количества на фактах + пересчёт) наступает при подписи — в {@see ProfileCompliance::signWriteOffAct()}.
 */
class WriteOffAct
{
    private readonly Uuid $id;
    private ProfileCompliance $profileCompliance;
    private string $requirementId;
    /** @var Collection<int, WriteOffItem> */
    private Collection $items;
    private DocumentStatus $status;
    private ?WriteOffCommission $commission = null;
    private ?string $actNumber = null;
    private ?\DateTimeImmutable $actDate = null;
    private ?string $scanFileId = null;
    private ?\DateTimeImmutable $signedAt = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, ProfileCompliance $profileCompliance, string $requirementId, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->requirementId = $requirementId;
        $this->items = new ArrayCollection();
        $this->status = DocumentStatus::Formed;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Положить порцию (черновик): если порция этого факта уже есть — увеличиваем количество, иначе добавляем. */
    public function addPortion(string $recordId, float $quantity, Uuid $portionId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $existing = $this->portionFor($recordId);
        if (null !== $existing) {
            $existing->addQuantity($quantity);
        } else {
            $this->items->add(new WriteOffItem($portionId, $this, $recordId, $quantity));
        }
        $this->updatedAt = $now;
    }

    public function removePortion(string $portionId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        foreach ($this->items as $item) {
            if ($item->getId() === $portionId) {
                $this->items->removeElement($item);
                $this->updatedAt = $now;

                return;
            }
        }
    }

    /** @param array<string, WriteOffReason> $reasonByPortionId */
    public function applyReasons(array $reasonByPortionId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        foreach ($this->items as $item) {
            if (isset($reasonByPortionId[$item->getId()])) {
                $item->setReason($reasonByPortionId[$item->getId()]);
            }
        }
        $this->updatedAt = $now;
    }

    public function portionFor(string $recordId): ?WriteOffItem
    {
        foreach ($this->items as $item) {
            if ($item->recordId() === $recordId) {
                return $item;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /** @return list<WriteOffItem> */
    public function items(): array
    {
        return array_values($this->items->toArray());
    }

    public function sign(WriteOffCommission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->assertReasonsComplete();
        if ('' === trim($scanFileId)) {
            throw new AppException('Приложите скан подписанного акта списания.');
        }
        $this->commission = $commission;
        $this->actNumber = trim($actNumber);
        $this->actDate = $actDate;
        $this->scanFileId = $scanFileId;
        $this->status = DocumentStatus::Signed;
        $this->signedAt = $now;
        $this->updatedAt = $now;
    }

    public function isDraft(): bool
    {
        return DocumentStatus::Formed === $this->status;
    }

    public function isSigned(): bool
    {
        return DocumentStatus::Signed === $this->status;
    }

    public function assertMutable(): void
    {
        if (!$this->isDraft()) {
            throw new AppException('Акт списания подписан — его нельзя изменить или удалить.');
        }
    }

    private function assertReasonsComplete(): void
    {
        foreach ($this->items as $item) {
            if (null === $item->reason()) {
                throw new AppException('Укажите причину списания для всех позиций акта.');
            }
        }
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function requirementId(): string
    {
        return $this->requirementId;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function commission(): ?WriteOffCommission
    {
        return $this->commission;
    }

    public function actNumber(): ?string
    {
        return $this->actNumber;
    }

    public function actDate(): ?\DateTimeImmutable
    {
        return $this->actDate;
    }

    public function scanFileId(): ?string
    {
        return $this->scanFileId;
    }

    public function signedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function profileCompliance(): ProfileCompliance
    {
        return $this->profileCompliance;
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
