<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Notifications\Domain\Type\NotificationType;

/**
 * Уведомляющее событие «запрос на подбор обработан» — адресовано владельцу запроса (Owner). Исход
 * (распознан/провален) приходит уже как bool — Notifications не знает enum Inquiries. Строит переходник
 * InquiryProcessedNotifier из доменного InquiryProcessed.
 */
final readonly class InquiryProcessedNotification implements NotifiableEvent, OwnedNotification, InquiryProcessedData
{
    public function __construct(
        private string $ownerUlid,
        private string $inquiryId,
        private bool $failed,
        private ?string $failureReason = null,
    ) {
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::InquiryProcessed;
    }

    public function ownerUlid(): string
    {
        return $this->ownerUlid;
    }

    public function inquiryId(): string
    {
        return $this->inquiryId;
    }

    public function isFailed(): bool
    {
        return $this->failed;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }
}
