<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\DTO;

final readonly class NoteDTO
{
    public function __construct(public ?string $id, public string $title, public string $description) {}
}
