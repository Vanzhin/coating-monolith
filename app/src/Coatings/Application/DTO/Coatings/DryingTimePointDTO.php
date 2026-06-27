<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

class DryingTimePointDTO
{
    public int $temperature_at;
    /** null = N/A; 0 = unlimited; >0 = duration в минутах. */
    public ?int $time_in_minutes = null;
    public bool $is_calculated = false;
}
