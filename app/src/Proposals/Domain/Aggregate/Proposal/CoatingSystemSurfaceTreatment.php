<?php

namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Shared\Domain\Trait\EnumToArray;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemSurfaceTreatment: string
{
    use EnumToArray;

    // Подготовка поверхности по ИСО 8501-1
    case SA1 = 'Sa 1 (по ГОСТ Р ИСО 8501-1-2014)';
    case SA2 = 'Sa 2 (по ГОСТ Р ИСО 8501-1-2014)';
    case SA25 = 'Sa 2,5 (по ГОСТ Р ИСО 8501-1-2014)';
    case SA3 = 'Sa 3 (по ГОСТ Р ИСО 8501-1-2014)';
    case ST2 = 'St2 (по ГОСТ Р ИСО 8501-1-2014)';
    case ST3 = 'St3 (по ГОСТ Р ИСО 8501-1-2014)';
}
