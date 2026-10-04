<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\NonMaterialItem;
use App\Compliance\Domain\ValueObject\Item\RequirementItemInterface;

/**
 * Тип требования — единственный источник истины о том, чем требование является. Два глобальных вида:
 * материальное (выдаётся в количестве — СИЗ) и нематериальное (событие без количества — инструктаж/медосмотр).
 * Позиция типа не хранит: свой класс она заявляет через {@see RequirementItemInterface::type()}, а тип требования
 * решает, какой класс позиции собрать из хранилища ({@see self::makeItem()}) и какую позицию принять.
 * Новый вид = новый case + своя ветка в makeItem + свой класс позиции; ядро (Requirement) не трогаем.
 */
enum ComplianceType: string
{
    case Material = 'material';
    case NonMaterial = 'non_material';

    public function title(): string
    {
        return match ($this) {
            self::Material => 'Выдача',
            self::NonMaterial => 'Процедура',
        };
    }

    /** Материальное требование выдаётся в количестве (СИЗ); нематериальное — событие без количества. */
    public function requiresQuantity(): bool
    {
        return self::Material === $this;
    }

    /**
     * Собрать позицию своего типа из массива (форма/jsonb). Единственная точка «тип → класс позиции»,
     * поэтому в хранилище дискриминатор не нужен: класс восстанавливается по типу требования.
     *
     * @param array<string, mixed> $data
     */
    public function makeItem(array $data): RequirementItemInterface
    {
        return match ($this) {
            self::Material => MaterialItem::fromArray($data),
            self::NonMaterial => NonMaterialItem::fromArray($data),
        };
    }
}
