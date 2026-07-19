<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance;

final readonly class DeleteSubstanceCommand
{
    public function __construct(public string $id)
    {
    }
}
