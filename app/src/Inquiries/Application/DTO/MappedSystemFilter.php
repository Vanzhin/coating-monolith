<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;

/**
 * Результат маппинга извлечённых параметров подбора систем в примитивный вход поиска Coatings +
 * предупреждения маппинга (нерезолв вещества, указанные заказчиком покрытия). CoatingSystemsFilterInput
 * — опубликованный примитивный контракт Coatings, Inquiries его домена не касается.
 */
final readonly class MappedSystemFilter
{
    /** @param list<string> $warnings */
    public function __construct(public CoatingSystemsFilterInput $input, public array $warnings = [])
    {
    }
}
