<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Note;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\InvalidArgumentException;

final class NoteTest extends TestCase
{
    public function test_construct(): void
    {
        $id = Uuid::v4();
        $n = new Note($id, 'Изменение цвета покрытия', 'Покрытие может поменять цвет…');
        self::assertSame($id->toRfc4122(), $n->getId());
        self::assertSame('Изменение цвета покрытия', $n->getTitle());
    }

    public function test_title_too_long(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Note(Uuid::v4(), str_repeat('a', 201), 'desc');
    }

    public function test_description_too_long(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Note(Uuid::v4(), 'title', str_repeat('a', 2001));
    }
}
