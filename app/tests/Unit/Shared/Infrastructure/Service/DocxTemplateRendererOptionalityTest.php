<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Опциональные маркеры региона {{x?}}…{{/x?}}: parse() должен распознавать блок как opt-регион,
 * не создавать фантомный блок '<name>?' из закрывающего маркера, и форма blocks[] — теперь
 * array{optional: bool, values: list<string>} вместо плоского list<string>. Плюс validate(): строгий
 * пустой регион → missing (файл не собрать), опциональный пустой → skipped (тихо выпадает).
 */
final class DocxTemplateRendererOptionalityTest extends TestCase
{
    use DocxFixtureTrait;

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

    public function test_required_empty_region_is_missing(): void
    {
        $tpl = $this->docxWithParagraphs(['{{note}}', 'Замечание: {{note}}', '{{/note}}']); // строгий (без ?)

        try {
            $res = (new DocxTemplateRenderer())->validate(new TemplateFile($tpl), new RenderData([])); // нет 'note'

            self::assertContains('note', $res->missing);
            self::assertFalse($res->isValid());
        } finally {
            @unlink($tpl);
        }
    }

    public function test_optional_empty_region_is_skipped_not_missing(): void
    {
        $tpl = $this->docxWithParagraphs(['{{note?}}', 'Замечание: {{note}}', '{{/note?}}']);

        try {
            $res = (new DocxTemplateRenderer())->validate(new TemplateFile($tpl), new RenderData([]));

            self::assertNotContains('note', $res->missing);
            self::assertContains('note', $res->skipped);
            self::assertTrue($res->isValid());
        } finally {
            @unlink($tpl);
        }
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
