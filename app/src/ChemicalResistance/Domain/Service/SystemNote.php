<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

final readonly class SystemNote
{
    public function __construct(public string $title, public string $description) {}
}
