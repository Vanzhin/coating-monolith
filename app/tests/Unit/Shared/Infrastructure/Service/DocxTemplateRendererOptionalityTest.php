<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TextValue;
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
     * Регресс на баг обрыва документа: PhpWord cloneBlock()/deleteBlock() матчат литерал маркера в
     * документе, а не логическое имя. Опциональный регион {{proc?}}…{{/proc?}} в тексте — литерал 'proc?',
     * не 'proc'; передача logical-имени без '?' не находит маркер → deleteBlock молча не удаляет регион,
     * контент ПОСЛЕ региона обрывается ({{leftover}}-guard рвёт документ). Пустой опц-блок должен уйти
     * целиком, а контент после — остаться целым.
     */
    public function test_empty_optional_block_removed_content_after_survives(): void
    {
        $tpl = $this->docxWithParagraphs([
            'Процесс:', '{{proc?}}', '1. {{proc}}', '{{/proc?}}',
            'Рекомендации: {{rec?}}',
        ]);

        try {
            $text = $this->render($tpl, new RenderData(['rec' => new TextValue('носить каску')])); // proc пуст

            self::assertStringNotContainsString('{{', $text);
            self::assertStringContainsString('Рекомендации: носить каску', $text); // контент после НЕ обрублен
            self::assertStringNotContainsString('1.', $text); // тело региона ушло
        } finally {
            @unlink($tpl);
        }
    }

    /**
     * Симметричная ветка того же бага: 'keep'-состояние опционального блока зовёт cloneBlock() тем же
     * логическим именем — без литерал-фикса регион с данными тоже не находится и остаётся сырым `{{note?}}`
     * в документе (leftover-guard кинул бы AppException).
     */
    public function test_optional_block_with_data_is_cloned_and_filled(): void
    {
        $tpl = $this->docxWithParagraphs(['{{note?}}', 'Замечание: {{note}}', '{{/note?}}', 'Конец.']);

        try {
            $text = $this->render($tpl, new RenderData(['note' => new TextValue('скол на кромке')]));

            self::assertStringNotContainsString('{{', $text);
            self::assertStringContainsString('Замечание: скол на кромке', $text);
            self::assertStringContainsString('Конец.', $text);
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
