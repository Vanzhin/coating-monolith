<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Опциональные маркеры региона {{x?}}…{{/x?}}: parse() должен распознавать блок как opt-регион,
 * не создавать фантомный блок '<name>?' из закрывающего маркера, и форма blocks[] — теперь
 * array{optional: bool, values: list<string>} вместо плоского list<string>.
 */
final class DocxTemplateRendererOptionalityTest extends TestCase
{
    public function test_optional_block_marker_parsed_as_optional_region(): void
    {
        $tpl = $this->docxWithParagraphs(['{{note?}}', 'Замечание: {{note}}', '{{/note?}}']);

        try {
            $parsed = $this->invokeParse($tpl);

            self::assertArrayHasKey('note', $parsed['blocks']);
            self::assertTrue($parsed['blocks']['note']['optional']);
            self::assertArrayNotHasKey('note?', $parsed['blocks']);
        } finally {
            @unlink($tpl);
        }
    }

    public function test_strict_block_marker_parsed_as_non_optional_region(): void
    {
        $tpl = $this->docxWithParagraphs(['{{recs}}', '{{recs.text}}', '{{/recs}}']);

        try {
            $parsed = $this->invokeParse($tpl);

            self::assertArrayHasKey('recs', $parsed['blocks']);
            self::assertFalse($parsed['blocks']['recs']['optional']);
        } finally {
            @unlink($tpl);
        }
    }

    public function test_value_inside_optional_block_marked_optional(): void
    {
        $tpl = $this->docxWithParagraphs(['{{note?}}', 'Замечание: {{note}}', '{{/note?}}']);

        try {
            $parsed = $this->invokeParse($tpl);

            $value = $this->findValue($parsed['values'], 'note');
            self::assertNotNull($value);
            self::assertTrue($value['optional']);
            self::assertSame('note', $value['block']);
        } finally {
            @unlink($tpl);
        }
    }

    public function test_blocks_values_membership_preserved_under_new_shape(): void
    {
        $tpl = $this->docxWithParagraphs(['{{note?}}', 'Замечание: {{note}}', '{{/note?}}']);

        try {
            $parsed = $this->invokeParse($tpl);

            self::assertSame(['note'], $parsed['blocks']['note']['values']);
        } finally {
            @unlink($tpl);
        }
    }

    /**
     * @param list<string> $paragraphs
     */
    private function docxWithParagraphs(array $paragraphs): string
    {
        $word = new PhpWord();
        $section = $word->addSection();
        foreach ($paragraphs as $paragraph) {
            $section->addText($paragraph);
        }

        $path = sys_get_temp_dir().'/opt_tpl_'.uniqid().'.docx';
        WordIO::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /**
     * @return array{
     *     values: list<array{logical: string, token: string, optional: bool, block: string|null}>,
     *     blocks: array<string, array{optional: bool, values: list<string>}>,
     *     repeats: array<string, array{subs: list<string>, anchor: string}>,
     *     segments: list<string>
     * }
     */
    private function invokeParse(string $templatePath): array
    {
        $renderer = new DocxTemplateRenderer();
        $rendererReflection = new \ReflectionClass($renderer);

        $loadMethod = $rendererReflection->getMethod('load');
        $processor = $loadMethod->invoke($renderer, new TemplateFile($templatePath));

        $parseMethod = $rendererReflection->getMethod('parse');

        /** @var array{values: list<array{logical: string, token: string, optional: bool, block: string|null}>, blocks: array<string, array{optional: bool, values: list<string>}>, repeats: array<string, array{subs: list<string>, anchor: string}>, segments: list<string>} $parsed */
        $parsed = $parseMethod->invoke($renderer, $processor);

        return $parsed;
    }

    /**
     * @param list<array{logical: string, token: string, optional: bool, block: string|null}> $values
     *
     * @return array{logical: string, token: string, optional: bool, block: string|null}|null
     */
    private function findValue(array $values, string $logical): ?array
    {
        foreach ($values as $value) {
            if ($value['logical'] === $logical) {
                return $value;
            }
        }

        return null;
    }
}
