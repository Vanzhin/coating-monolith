<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Периодичность обязанности: вид + (для «каждые N») число и единица периода. Периодический вид требует
 * положительного числа и единицы. «Однократно»/«по факту»/«по документам изготовителя» срок автоматически
 * не считают: у первых двух его нет вовсе, у последнего конкретная дата вводится при выдаче (Д3).
 */
final readonly class Cadence implements \JsonSerializable
{
    public CadenceKind $kind;
    public ?int $number;
    public ?PeriodUnit $unit;

    public function __construct(CadenceKind $kind, ?int $number = null, ?PeriodUnit $unit = null)
    {
        if (CadenceKind::Periodic === $kind) {
            if (null === $number || $number <= 0) {
                throw new AppException('Укажите положительное число для периодичности «каждые N».');
            }
            if (null === $unit) {
                throw new AppException('Выберите единицу периода (месяцы или годы).');
            }
            $this->number = $number;
            $this->unit = $unit;
        } else {
            $this->number = null;
            $this->unit = null;
        }
        $this->kind = $kind;
    }

    /** Дата следующего срока от базовой. Периодическая → дата; остальные → null. */
    public function nextDueFrom(\DateTimeImmutable $base): ?\DateTimeImmutable
    {
        if (CadenceKind::Periodic === $this->kind && null !== $this->unit && null !== $this->number) {
            return $this->unit->addTo($base, $this->number);
        }

        return null;
    }

    public function isPeriodic(): bool
    {
        return $this->kind->isPeriodic();
    }

    public function label(): string
    {
        return match ($this->kind) {
            CadenceKind::Once => 'однократно',
            CadenceKind::Periodic => $this->periodicLabel(),
            CadenceKind::ByFact => 'по факту',
            CadenceKind::ByManufacturerDoc => 'по документам изготовителя',
        };
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            CadenceKind::from((string) $data['kind']),
            isset($data['number']) ? (int) $data['number'] : null,
            isset($data['unit']) && '' !== (string) $data['unit'] ? PeriodUnit::from((string) $data['unit']) : null,
        );
    }

    /** @return array{kind: string, number: int|null, unit: string|null} */
    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind->value, 'number' => $this->number, 'unit' => $this->unit?->value];
    }

    private function periodicLabel(): string
    {
        if (null === $this->number || null === $this->unit) {
            return 'каждые N (мес./лет)';
        }
        if (1 === $this->number) {
            return PeriodUnit::Year === $this->unit ? 'ежегодно' : 'ежемесячно';
        }

        return sprintf('каждые %d %s', $this->number, $this->unit->pluralFor($this->number));
    }
}
