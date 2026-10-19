<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ReadModel;

use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\ObligationView;
use App\Compliance\Domain\ValueObject\Unit;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only проекция обязанности человека (мониторинг/дашборд) — денормализованная строка на
 * (profileId, requirementId, itemId). Материализует её ТОЛЬКО проектор, через
 * {@see \App\Compliance\Infrastructure\Repository\ObligationProjectionRepository::upsertMany} (DBAL,
 * минуя ORM); этот класс используется строго для чтения через DQL — ORM его не персистит.
 * Идентичность строки — {@see self::key()}, тот же формат, что у {@see \App\Compliance\Domain\ValueObject\ObligationKey::keyOf}.
 */
class ObligationProjection implements ObligationView
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
    private ?Cadence $cadenceStruct;
    // @phpstan-ignore-next-line property.onlyRead
    private ?float $quantity;
    // @phpstan-ignore-next-line property.onlyRead
    private ?string $quantityUnit;
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

    /** Готовый label-снимок периодичности («ежегодно») — для отображения (дашборд/карточка), без похода в структуру. */
    public function cadence(): string
    {
        return $this->cadence;
    }

    /**
     * Структурная периодичность (kind+число+единица) — для форм, которым нужно её ПЕРЕСЧИТАТЬ (предпросмотр
     * «след. выдача» на `/issue`), а не просто показать. JSONB-колонка, переиспользует
     * {@see \App\Compliance\Infrastructure\Database\DBAL\CadenceType}.
     */
    public function cadenceStruct(): ?Cadence
    {
        return $this->cadenceStruct;
    }

    public function quantity(): ?float
    {
        return $this->quantity;
    }

    /** {@see ObligationView}: норма — то же, что {@see self::quantity()}, именем интерфейса резолвера. */
    public function normQuantity(): ?float
    {
        return $this->quantity;
    }

    /** Единица нормы {@see self::quantity()} — для предзаполнения формы выдачи (норма + персональные позиции). */
    public function quantityUnit(): ?Unit
    {
        return null !== $this->quantityUnit ? Unit::from($this->quantityUnit) : null;
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

    /** {@see ObligationView}: периодическая — по своей колонке `type`, без похода в отдельный класс позиции. */
    public function isPeriodic(): bool
    {
        return ComplianceType::Periodic->value === $this->type;
    }

    /** {@see \App\Compliance\Domain\Type\ObligationOrigin} — строкой (`->value`), без завязки на enum в интерфейсе. */
    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * Инструктаж — периодическая позиция с нормой «1 шт.»: количество технического смысла не несёт
     * («прошёл/не прошёл»), поэтому экраны выдачи/карточки его не показывают. ЕДИНСТВЕННОЕ место предиката —
     * читатели (DTO-трансформеры) переносят результат дальше готовым булем, не пересчитывают условие сами.
     * Не используется резолвером/бакетом — там количество полноценный инвариант недовыдачи для любой нормы.
     */
    public function isInstructionLike(): bool
    {
        return $this->isPeriodic()
            && Unit::Piece === $this->quantityUnit()
            && null !== $this->quantity()
            && abs($this->quantity() - 1.0) < ComplianceBucketResolver::QUANTITY_EPSILON;
    }

    /** Идентичность строки — `requirementId|itemId`, формат как у {@see \App\Compliance\Domain\ValueObject\ObligationKey::keyOf}. */
    public function key(): string
    {
        return $this->requirementId.'|'.$this->itemId;
    }
}
