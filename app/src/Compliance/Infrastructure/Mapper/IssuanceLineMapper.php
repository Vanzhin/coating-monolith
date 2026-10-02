<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Mapper;

use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Shape-маппер строк выдачи из формы в {@see IssuanceLine}: разбор дат/количества/износа. Только форма;
 * бизнес-проверка «не меньше нормы» — в домене ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance::assertIssuable()}).
 */
final class IssuanceLineMapper
{
    /**
     * @param list<array<string, mixed>> $items
     *
     * @return IssuanceLine[]
     */
    public function fromInput(array $items, \DateTimeImmutable $fallbackDate): array
    {
        $lines = [];
        foreach ($items as $row) {
            $key = trim((string) ($row['obligationKey'] ?? ''));
            if ('' === $key) {
                continue;
            }
            $lines[] = new IssuanceLine(
                Uuid::v7(),
                $key,
                $this->date((string) ($row['date'] ?? '')) ?? $fallbackDate,
                $this->quantity($row),
                $this->wear($row),
                $this->date((string) ($row['manualDueDate'] ?? '')),
            );
        }

        return $lines;
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            ?: throw new AppException(sprintf('Неверный формат даты: «%s».', $value));
    }

    /** @param array<string, mixed> $row */
    private function quantity(array $row): ?Quantity
    {
        $amount = trim((string) ($row['amount'] ?? ''));
        if ('' === $amount) {
            return null;
        }
        $unit = Unit::tryFrom((string) ($row['unit'] ?? '')) ?? throw new AppException('Выберите единицу измерения количества.');

        return new Quantity((float) str_replace(',', '.', $amount), $unit);
    }

    /** @param array<string, mixed> $row */
    private function wear(array $row): ?Percent
    {
        $wear = trim((string) ($row['wearPercent'] ?? ''));

        return '' === $wear ? null : new Percent((float) str_replace(',', '.', $wear));
    }
}
