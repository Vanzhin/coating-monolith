<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Item\RequirementItemInterface;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Собирает `RequirementItemInterface[]` из плоского ввода формы (shape → массив → {@see ComplianceType::makeItem()}).
 * Тип решает, какой класс позиции собрать: материальному и периодическому нужно количество, нематериальному —
 * нет; режим (инвентарный/расходный) есть только у материального. Правила (положительность, основание,
 * допустимость числа периодичности) — в самих VO; здесь только парсинг shape и понятная ошибка на неизвестном
 * enum-значении. Полностью пустые строки (без наименования) пропускаем.
 */
final readonly class RequirementItemBuilder
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return RequirementItemInterface[]
     */
    public function build(ComplianceType $type, array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || '' === trim((string) ($row['label'] ?? ''))) {
                continue;
            }

            $id = trim((string) ($row['id'] ?? ''));
            $data = [
                'id' => '' !== $id ? $id : Uuid::v7()->toRfc4122(),
                'label' => (string) $row['label'],
                'cadence' => $this->cadence($row),
                'basis' => (string) ($row['basis'] ?? ''),
            ];
            if ($type->requiresQuantity()) {
                $data['quantity'] = $this->quantity($row);
            }
            if (ComplianceType::Material === $type) {
                $data['mode'] = (MaterialMode::tryFrom((string) ($row['mode'] ?? '')) ?? MaterialMode::Inventoried)->value;
            }

            $items[] = $type->makeItem($data);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{kind: string, number?: int|null, unit?: string}
     */
    private function cadence(array $row): array
    {
        $kind = CadenceKind::tryFrom((string) ($row['cadenceKind'] ?? ''));
        if (null === $kind) {
            throw new AppException('Выберите периодичность позиции.');
        }
        if (CadenceKind::Periodic !== $kind) {
            return ['kind' => $kind->value];
        }

        $unit = PeriodUnit::tryFrom((string) ($row['cadenceUnit'] ?? ''));
        if (null === $unit) {
            throw new AppException('Выберите единицу периода (месяцы или годы).');
        }

        return [
            'kind' => $kind->value,
            'number' => '' !== trim((string) ($row['cadenceNumber'] ?? '')) ? (int) $row['cadenceNumber'] : null,
            'unit' => $unit->value,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{amount: float, unit: string}
     */
    private function quantity(array $row): array
    {
        $unit = Unit::tryFrom((string) ($row['unit'] ?? ''));
        if (null === $unit) {
            throw new AppException('Выберите единицу измерения количества.');
        }

        return [
            'amount' => (float) str_replace(',', '.', (string) ($row['amount'] ?? '')),
            'unit' => $unit->value,
        ];
    }
}
