<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance;

use App\Shared\Application\Command\CommandInterface;

final readonly class CreateSubstanceCommand implements CommandInterface
{
    /** @param list<string> $aliases */
    public function __construct(
        public string $canonicalName,
        public ?string $cas,
        public array $aliases,
    ) {
    }
}
