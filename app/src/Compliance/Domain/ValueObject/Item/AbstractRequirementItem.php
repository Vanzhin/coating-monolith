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
    /** Предел длины наименования — проекция (TrackedObligation.label) должна его вмещать, иначе пересборка откатится. */
    public const MAX_LABEL_LENGTH = 1000;

    private string $label;
    private string $basis;

    public function __construct(string $label, private Cadence $cadence, string $basis)
    {
        $label = trim($label);
        if ('' === $label) {
            throw new AppException('Укажите наименование позиции.');
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new AppException(sprintf('Наименование позиции слишком длинное (максимум %d символов).', self::MAX_LABEL_LENGTH));
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
