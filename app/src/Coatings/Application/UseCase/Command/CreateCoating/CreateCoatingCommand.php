<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\CreateCoating;

use App\Shared\Application\Command\Command;

readonly class CreateCoatingCommand extends Command
{
    public function __construct(
        public string $description,
        public string $title,
        public int    $volumeSolid,
        public float  $massDensity,
        public int    $tdsDft,
        public int    $minDft,
        public int    $maxDft,
        public int    $applicationMinTemp,
        public int    $dryToTouch,
        public int    $minRecoatingInterval,
        public int    $maxRecoatingInterval,
        public int    $fullCure,
        public string $manufacturerId,
    )
    {
    }
}
