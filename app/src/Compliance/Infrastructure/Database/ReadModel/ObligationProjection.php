<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\ReadModel;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only проекция обязанности человека (мониторинг/дашборд) — денормализованная строка на
 * (profileId, requirementId, itemId). Материализует её ТОЛЬКО проектор, через
 * {@see \App\Compliance\Infrastructure\Repository\ObligationProjectionRepository::upsertMany} (DBAL,
 * минуя ORM); этот класс используется строго для чтения через DQL — ORM его не персистит.
 * Идентичность строки — {@see self::key()}, тот же формат, что у `TrackedObligation::key()`.
 */
class ObligationProjection
{
    // Свойства наполняются только ORM hydration (read-only entity, без конструктора) — phpstan не видит
    // присвоения, суппрессия на каждом поле (приём как у App\Coatings\Domain\Aggregate\Coating\CoatingSearch).
    // @phpstan-ignore-next-line property.onlyRead
    private Uuid $id;
    // @phpstan-ignore-next-line property.onlyRead
    private string $profileId;
    // @phpstan-ignore-next-line property.onlyRead
    private string $requirementId;
    // @phpstan-ignore-next-line property.onlyRead
    private string $itemId;
    // @phpstan-ignore-next-line property.onlyRead
    private ?string $requirementName;
    // @phpstan-ignore-next-line property.onlyRead
    private string $label;
    // @phpstan-ignore-next-line property.onlyRead
    private string $type;
    // @phpstan-ignore-next-line property.onlyRead
    private string $cadence;
    // @phpstan-ignore-next-line property.onlyRead
    private ?float $quantity;
    // @phpstan-ignore-next-line property.onlyRead
    private ?string $departmentId;
    // @phpstan-ignore-next-line property.onlyRead
    private ?\DateTimeImmutable $lastFulfilledAt;
    // @phpstan-ignore-next-line property.onlyRead
    private ?\DateTimeImmutable $nextDueAt;
    // @phpstan-ignore-next-line property.onlyRead
    private float $heldQuantity;
    // @phpstan-ignore-next-line property.onlyRead
    private bool $active;
    // @phpstan-ignore-next-line property.onlyRead
    private string $origin;
    // @phpstan-ignore-next-line property.onlyRead
    private ?string $mode;

    public function id(): string
    {
        return (string) $this->id;
    }

    public function profileId(): string
    {
        return $this->profileId;
    }

    public function requirementId(): string
    {
        return $this->requirementId;
    }

    public function itemId(): string
    {
        return $this->itemId;
    }

    public function requirementName(): ?string
    {
        return $this->requirementName;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function type(): ComplianceType
    {
        return ComplianceType::from($this->type);
    }

    /** Снимок периодичности (как у `Cadence::label()`/`CadenceKind`) — хранится строкой, полный VO не восстановить без number/unit. */
    public function cadence(): string
    {
        return $this->cadence;
    }

    public function quantity(): ?float
    {
        return $this->quantity;
    }

    public function departmentId(): ?string
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

    public function heldQuantity(): float
    {
        return $this->heldQuantity;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** `TrackedObligation::ORIGIN_NORM`/`ORIGIN_PERSONAL` — строкой, без завязки на доменный класс. */
    public function origin(): string
    {
        return $this->origin;
    }

    public function mode(): ?MaterialMode
    {
        return null !== $this->mode ? MaterialMode::from($this->mode) : null;
    }

    /** Идентичность строки — `requirementId|itemId`, формат как у `TrackedObligation::keyOf()`. */
    public function key(): string
    {
        return $this->requirementId.'|'.$this->itemId;
    }
}
