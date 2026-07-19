<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Infrastructure\Docx;

use App\ChemicalResistance\Infrastructure\Docx\DocxAssessmentParser;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class DocxAssessmentParserTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = dirname(__DIR__, 4).'/Fixtures/ChemicalResistance/minimal.docx';
    }

    public function test_parses_minimal_fixture(): void
    {
        $out = (new DocxAssessmentParser())->parse($this->fixturePath);

        self::assertGreaterThanOrEqual(100, count($out->rows));
        self::assertSame('(Balsamöl) (G)', $out->rows[0]->substanceName);
        self::assertSame('R', $out->rows[0]->gradeCell);
        self::assertSame('(Terpentinoel) (G)', $out->rows[1]->substanceName);
        self::assertSame('R', $out->rows[1]->gradeCell);

        self::assertGreaterThanOrEqual(1, count($out->notes));
        self::assertSame('Прим. 1', $out->notes[0]->label);
        self::assertSame('Изменение цвета покрытия', $out->notes[0]->title);
        self::assertStringContainsString('поменять цвет', $out->notes[0]->description);
    }

    public function test_parses_unreadable_file(): void
    {
        $this->expectException(AppException::class);
        (new DocxAssessmentParser())->parse('/no/such/file.docx');
    }
}
