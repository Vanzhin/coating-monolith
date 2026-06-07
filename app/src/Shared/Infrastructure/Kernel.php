<?php

namespace App\Shared\Infrastructure;

use App\Coatings\Infrastructure\Database\DBAL\DftRangeType;
use App\Coatings\Infrastructure\Database\DBAL\DryingTimeSeriesType;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        $this->registerDoctrineTypes();
    }

    private function registerDoctrineTypes(): void
    {
        $customTypes = [
            DryingTimeSeriesType::NAME => DryingTimeSeriesType::class,
            DftRangeType::NAME => DftRangeType::class,
        ];

        foreach ($customTypes as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
    }
}
