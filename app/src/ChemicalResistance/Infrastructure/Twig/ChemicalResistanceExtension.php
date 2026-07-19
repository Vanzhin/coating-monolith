<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Twig;

use App\ChemicalResistance\Domain\Service\SystemNotes;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ChemicalResistanceExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('system_notes', fn () => SystemNotes::all()),
        ];
    }
}
