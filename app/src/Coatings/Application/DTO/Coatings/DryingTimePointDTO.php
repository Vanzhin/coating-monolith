<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

class DryingTimePointDTO
{
    public int $temperature_at;
    public int $time_in_minutes;
    public bool $is_calculated = false;
}
