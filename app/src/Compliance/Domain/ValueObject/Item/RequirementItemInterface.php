<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;

/**
 * Позиция требования: что положено/что нужно пройти. Свой тип позиция заявляет через {@see type()} —
 * это её класс, а не хранимое поле. Требование принимает позицию, только если её тип совпадает с типом
 * требования ({@see \App\Compliance\Domain\Aggregate\Requirement\Requirement::supports()}).
 */
interface RequirementItemInterface extends \JsonSerializable
{
    public function type(): ComplianceType;

    public function label(): string;

    public function cadence(): Cadence;

    public function basis(): string;
}
