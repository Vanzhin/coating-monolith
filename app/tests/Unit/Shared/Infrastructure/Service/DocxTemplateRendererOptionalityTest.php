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
     * Инлайн-регион {{sensor?}}Датчик: {{sensor}}{{/sensor?}} — оба маркера в ОДНОМ абзаце. parse() должен
     * распознать это автоматически (не по паре close-маркеров, а по отсутствию границы абзаца между ними)
     * и НЕ регистрировать 'sensor' как блок — иначе деleteBlock/cloneBlock (абзацного уровня) не найдут
     * маркеры внутри одной строки и порвут документ.
     */
    public function test_inline_optional_region_not_registered_as_block(): void
    {
        $tpl = $this->docxWithInline('{{sensor?}}Датчик: {{sensor}}{{/sensor?}}');

        try {
            $parsed = $this->invokeParse($tpl);

            self::assertArrayNotHasKey('sensor', $parsed['blocks']);
            self::assertContains('sensor', $parsed['inlineRegions']);
        } finally {
            @unlink($tpl);
        }
    }

    public function test_inline_optional_region_cut_when_empty(): void
    {
        $tpl = $this->docxWithInline('{{sensor?}}Датчик: {{sensor}}{{/sensor?}}');

        try {
            self::assertStringNotContainsString('Датчик', $this->render($tpl, new RenderData([])));
            self::assertStringContainsString('Датчик: A1', $this->render($tpl, new RenderData(['sensor' => new TextValue('A1')])));
        } finally {
            @unlink($tpl);
        }
    }

    /**
     * Регресс на баг обрыва документа (тот же класс, что и test_empty_optional_block_removed_content_after_survives,
     * но для маркеров в одной строке): текст ПОСЛЕ инлайн-региона не должен обрубаться, когда региона нет.
     */
    public function test_inline_optional_region_removed_content_after_survives(): void
    {
        $tpl = $this->docxWithInline('{{sensor?}}Датчик: {{sensor}}{{/sensor?}} Конец.');

        try {
            $text = $this->render($tpl, new RenderData([]));

            self::assertStringNotContainsString('{{', $text);
            self::assertStringNotContainsString('Датчик', $text);
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
