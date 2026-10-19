<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\ValueObject\Quantity;

/**
 * Позиция, несущая количество ({@see \App\Compliance\Domain\Type\ComplianceType::requiresQuantity()} — материальная
 * и периодическая, СИБЛИНГИ по {@see RequirementItemInterface}, обе независимо наследуют {@see AbstractRequirementItem}).
 * Маркер нужен проектору/мапперам, чтобы читать норму ({@see self::quantity()}) без `instanceof MaterialItem`,
 * который у периодической позиции ложно отрицательный — она не `instanceof MaterialItem`, хоть и количественная.
 */
interface QuantifiedItem extends RequirementItemInterface
{
    public function quantity(): Quantity;
}
