<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;
use Symfony\Component\Uid\Uuid;

/**
 * Проекция обязанности человека — производная (норма + факты), материализованная строкой ради дешёвых
 * запросов дашборда/алертов (Д4). Хранит ДАТЫ (событийные), но НЕ статус (выводится на чтении). `active` =
 * документ требования (человек×требование) подписан — только тогда трекаем сроки; иначе требование не
 * исполнено (Red). Идентичность строки — `key()` = `requirementId|label`, переживает пересборку.
 */
class TrackedObligation
{
    public const ORIGIN_NORM = 'norm';
    public const ORIGIN_PERSONAL = 'personal';

    private readonly Uuid $id;
    private ProfileCompliance $profileCompliance;
    private string $requirementId;
    private string $requirementName;
    private string $label;
    private ComplianceType $type;
    private Cadence $cadence;
    private ?Quantity $quantity;
    private string $departmentId;
    private ?\DateTimeImmutable $lastFulfilledAt = null;
    private ?\DateTimeImmutable $nextDueAt = null;
    private float $heldQuantity = 0.0;
    private bool $active = false;
    private string $origin;

    public function __construct(
        Uuid $id,
        ProfileCompliance $profileCompliance,
        string $requirementId,
        string $requirementName,
        string $label,
        ComplianceType $type,
        Cadence $cadence,
        ?Quantity $quantity,
        string $departmentId,
        string $origin = self::ORIGIN_NORM,
    ) {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->requirementId = $requirementId;
        $this->requirementName = $requirementName;
        $this->label = $label;
        $this->type = $type;
        $this->cadence = $cadence;
        $this->quantity = $quantity;
        $this->departmentId = $departmentId;
        $this->origin = $origin;
    }

    public static function keyOf(string $requirementId, string $label): string
    {
        return $requirementId.'|'.mb_strtolower(trim($label));
    }

    public function key(): string
    {
        return self::keyOf($this->requirementId, $this->label);
    }

    public function setDates(?\DateTimeImmutable $lastFulfilledAt, ?\DateTimeImmutable $nextDueAt): void
    {
        $this->lastFulfilledAt = $lastFulfilledAt;
        $this->nextDueAt = $nextDueAt;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function heldQuantity(): float
    {
        return $this->heldQuantity;
    }

    public function setHeldQuantity(float $heldQuantity): void
    {
        $this->heldQuantity = $heldQuantity;
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

    public function requirementName(): string
    {
        return $this->requirementName;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function type(): ComplianceType
    {
        return $this->type;
    }

    public function cadence(): Cadence
    {
        return $this->cadence;
    }

    public function quantity(): ?Quantity
    {
        return $this->quantity;
    }

    public function departmentId(): string
    {
        return $this->departmentId;
    }

    public function lastFulfilledAt(): ?\DateTimeImmutable
    {
        return $this->lastFulfilledAt;
    }

    public function nextDueAt(): ?\DateTimeImmutable
    {
        return $this->nextDueAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function origin(): string
    {
        return $this->origin;
    }
}
