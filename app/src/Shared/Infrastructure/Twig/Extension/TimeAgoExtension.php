<?php

namespace App\Shared\Infrastructure\Twig\Extension;

use Carbon\Carbon;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TimeAgoExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [

            new TwigFilter('timeAgo', [$this, 'getDif']),
        ];
    }

    public function getDif($value): string
    {
        return Carbon::make($value)->locale('ru')->diffForHumans();
    }
}
