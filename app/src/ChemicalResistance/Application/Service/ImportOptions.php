<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\Service;

final readonly class ImportOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $overwrite = false,
        public int $defaultMaxTemp = 40,
    ) {}
}
