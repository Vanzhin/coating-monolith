<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Note;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NoteTest extends TestCase
{
    public function testConstruct(): void
    {
        $id = Uuid::v4();
        $n = new Note($id, 'Изменение цвета покрытия', 'Покрытие может поменять цвет…');
        self::assertSame($id->toRfc4122(), $n->getId());
        self::assertSame('Изменение цвета покрытия', $n->getTitle());
    }

    public function testTitleTooLong(): void
    {
        $this->expectException(AppException::class);
        new Note(Uuid::v4(), str_repeat('a', 201), 'desc');
    }

    public function testDescriptionTooLong(): void
    {
        $this->expectException(AppException::class);
        new Note(Uuid::v4(), 'title', str_repeat('a', 2001));
    }
}
