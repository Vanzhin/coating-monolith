<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\ChemicalResistance\Domain\Service\EffectiveAssessmentNotes;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EffectiveAssessmentNotesTest extends TestCase
{
    public function testSystemNotesFirstThenStoredInOrder(): void
    {
        $n1 = new Note(Uuid::v4(), 'Прим. 1', 'text1');
        $n2 = new Note(Uuid::v4(), 'Прим. 4', 'text4');

        $notes = $this->createMock(NoteRepository::class);
        $notes->method('findAllByIds')
            ->with([$n1->getId(), $n2->getId()])
            ->willReturn([$n1, $n2]);

        $a = $this->createMock(Assessment::class);
        $a->method('getNoteIds')->willReturn(new StringCollection($n1->getId(), $n2->getId()));

        $resolver = new EffectiveAssessmentNotes($notes);
        $views = $resolver->of($a);

        self::assertGreaterThanOrEqual(2, count($views));
        self::assertTrue($views[0]->isSystem);
        // Last two must be stored, in noteIds order.
        self::assertFalse($views[count($views)-2]->isSystem);
        self::assertSame('Прим. 1', $views[count($views)-2]->title);
        self::assertSame('Прим. 4', $views[count($views)-1]->title);
    }
}
