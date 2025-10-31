<?php
declare(strict_types=1);

namespace App\Proposals\Domain\Service;

class CoatingData
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly int $volumeSolid,
        public readonly float $massDensity,
        public readonly int $tdsDft,
        public readonly int $minDft,
        public readonly int $maxDft,
        public readonly int $applicationMinTemp,
        public readonly float $dryToTouch,
        public readonly float $minRecoatingInterval,
        public readonly float $maxRecoatingInterval,
        public readonly float $fullCure,
        public readonly float $pack,
        public readonly ?string $thinner
    ) {
    }
}
