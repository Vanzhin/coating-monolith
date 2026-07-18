<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;

final readonly class NoteView
{
    private function __construct(public string $title, public string $description, public bool $isSystem) {}

    public static function system(SystemNote $n): self { return new self($n->title, $n->description, true); }
    public static function stored(Note $n): self { return new self($n->getTitle(), $n->getDescription(), false); }
}
