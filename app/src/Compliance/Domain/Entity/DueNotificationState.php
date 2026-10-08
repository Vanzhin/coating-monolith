<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Entity;

use App\Compliance\Domain\Type\ComplianceBucket;
use Symfony\Component\Uid\Uuid;

/**
 * Антифлуд-маркер прохода уведомлений: последнее известное состояние (бакет) обязанности (человек×позиция) и
 * когда по ней в последний раз слали. Отдельная таблица (НЕ поле проекции TrackedObligation): переживает
 * пересборку проекции — иначе маркер обнулялся бы и шла повторная рассылка.
 *
 * Проход сравнивает текущий бакет с хранимым и решает, слать ли (политика — отдельно). Запись принадлежит
 * процессу рассылки, не доменному инварианту ProfileCompliance.
 */
class DueNotificationState
{
    public function __construct(
        private readonly Uuid $id,
        private readonly string $profileId,
        private readonly string $obligationKey,
        private ComplianceBucket $bucket,
        private ?\DateTimeImmutable $lastNotifiedAt,
    ) {
    }

    public static function initial(string $profileId, string $obligationKey, ComplianceBucket $bucket): self
    {
        return new self(Uuid::v7(), $profileId, $obligationKey, $bucket, null);
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function profileId(): string
    {
        return $this->profileId;
    }

    public function obligationKey(): string
    {
        return $this->obligationKey;
    }

    public function bucket(): ComplianceBucket
    {
        return $this->bucket;
    }

    public function lastNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastNotifiedAt;
    }

    /** Зафиксировать новое состояние; если $notified — отметить, что по обязанности только что уведомили. */
    public function record(ComplianceBucket $bucket, bool $notified, \DateTimeImmutable $now): void
    {
        $this->bucket = $bucket;
        if ($notified) {
            $this->lastNotifiedAt = $now;
        }
    }
}
