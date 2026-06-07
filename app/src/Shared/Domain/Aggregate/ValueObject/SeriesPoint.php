<?php
declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use JsonSerializable;

interface SeriesPoint extends JsonSerializable
{
    public function getKey(): int|float;
    public function getValue(): int|float;
    public function isCalculated(): bool;
}
