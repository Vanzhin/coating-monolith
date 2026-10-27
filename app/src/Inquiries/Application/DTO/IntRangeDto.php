<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Диапазон {from, to} из извлечения — обе границы опциональны (модель могла указать
 * одну). В доменный RangeFilter превращается в маппере (план 2), тут — просто перенос.
 */
final readonly class IntRangeDto
{
    public function __construct(
        public ?int $from,
        public ?int $to,
    ) {
    }
}
