<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance;

final readonly class CreateSubstanceCommand
{
    /** @param list<string> $aliases */
    public function __construct(
        public string $canonicalName,
        public ?string $cas,
        public array $aliases,
    ) {
    }
}
