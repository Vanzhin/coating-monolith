<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Mapper;

use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
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
                $this->quantityOf($row),
                $this->wear($row),
                $this->date((string) ($row['manualDueDate'] ?? '')),
                $this->note($row),
                $this->instructionDetails($row),
            );
        }

        return $lines;
    }

    /** @param array<string, mixed> $row Разбор количества строки (amount+unit) — переиспользуется для персональных позиций. */
    public function quantityOf(array $row): ?Quantity
    {
        $amount = trim((string) ($row['amount'] ?? ''));
        if ('' === $amount) {
            return null;
        }
        $unit = Unit::tryFrom((string) ($row['unit'] ?? '')) ?? throw new AppException('Выберите единицу измерения количества.');

        return new Quantity((float) str_replace(',', '.', $amount), $unit);
    }

    /** @param array<string, mixed> $row Модель/марка/артикул выдаваемого (в факт-таблицу карточки) — переиспользуется для персональных позиций. */
    public function note(array $row): ?string
    {
        $note = trim((string) ($row['note'] ?? ''));

        return '' === $note ? null : $note;
    }

    /**
     * Поля инструктажа строки (sub-array `instruction[...]` формы) → VO. Shape-only: берём, что пришло по
     * схеме журнала; обязательность проверяет домен при подписи. Пусто → null.
     *
     * @param array<string, mixed> $row
     */
    public function instructionDetails(array $row): ?InstructionDetails
    {
        $raw = $row['instruction'] ?? null;
        if (!is_array($raw) || [] === $raw) {
            return null;
        }

        return InstructionDetails::fromArray($raw);
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
    private function wear(array $row): ?Percent
    {
        $wear = trim((string) ($row['wearPercent'] ?? ''));

        return '' === $wear ? null : new Percent((float) str_replace(',', '.', $wear));
    }
}
