<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance;

use App\Shared\Application\Command\CommandInterface;

final readonly class DeleteSubstanceCommand implements CommandInterface
{
    public function __construct(public string $id)
    {
    }
}
