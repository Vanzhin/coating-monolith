<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\ValueObject\Cadence;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Общие поля позиции и их валидация. Конкретный тип (материальный/нематериальный) добавляет свои поля
 * и реализует {@see RequirementItemInterface::type()}.
 */
abstract readonly class AbstractRequirementItem implements RequirementItemInterface
{
    private string $label;
    private string $basis;

    public function __construct(string $label, private Cadence $cadence, string $basis)
    {
        $label = trim($label);
        if ('' === $label) {
            throw new AppException('Укажите наименование позиции.');
        }
        $this->label = $label;

        $basis = trim($basis);
        if ('' === $basis) {
            throw new AppException(sprintf('Укажите основание (по какой причине) для «%s».', $label));
        }
        $this->basis = $basis;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function cadence(): Cadence
    {
        return $this->cadence;
    }

    public function basis(): string
    {
        return $this->basis;
    }

    /**
     * Общая часть сериализации; конкретный тип дополняет своими полями.
     *
     * @return array{label: string, cadence: array{kind: string, number: int|null}, basis: string}
     */
    protected function baseArray(): array
    {
        return [
            'label' => $this->label,
            'cadence' => $this->cadence->jsonSerialize(),
            'basis' => $this->basis,
        ];
    }
}
