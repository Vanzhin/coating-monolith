<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\DTO;

final readonly class SubstanceDTO
{
    public function __construct(
        public ?string $id,
        public string $canonicalName,
        public ?string $cas,              // "107-21-1" or null
        /** @var list<string> */
        public array $aliases,
    ) {
    }
}
