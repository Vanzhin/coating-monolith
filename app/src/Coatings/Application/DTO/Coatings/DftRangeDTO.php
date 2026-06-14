<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

class DftRangeDTO
{
    public int $min;
    public int $max;
    public int $tds_dft;

    /** ThicknessType value, например 'mic'. */
    public string $type;
}
