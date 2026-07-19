<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;

final class EffectiveAssessmentNotes
{
    public function __construct(private NoteRepositoryInterface $notes) {}

    /** @return list<NoteView> */
    public function of(Assessment $a): array
    {
        $stored = $this->notes->findAllByIds($a->getNoteIds()->getList());
        $out = array_map(fn(SystemNote $n) => NoteView::system($n), SystemNotes::all());
        foreach ($stored as $n) { $out[] = NoteView::stored($n); }
        return $out;
    }
}
