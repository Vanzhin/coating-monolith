<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TextValue;
use PHPUnit\Framework\TestCase;

/**
 * Легаси инлайн-сегмент {{?name}}…{{/?name}} удалён — единый инлайн-регион {{name?}}…{{/name?}}
 * (см. DocxTemplateRendererOptionalityTest) полностью его заменил, тот же приём «вырезать литерал вместе
 * с плейсхолдером, когда данных нет», но на едином `?`-суффикс синтаксисе. Row-level инлайн-подполя
 * внутри повторяемой строки/блока (то, что раньше снимал resolveRowSegments по #i) сознательно выпали
 * вместе с сегментом — редкая ниша, отдельной задачей не заведена.
 */
final class DocxTemplateRendererSegmentTest extends TestCase
{
    use DocxFixtureTrait;

    public function test_prefix_segment_syntax_is_no_longer_special(): void
    {
        // Легаси {{?name}} теперь не спец-синтаксис — движок не падает на нём при разборе, а ключ
        // 'segments' (снятая с parse() ветка) в результате отсутствует в принципе.
        $tpl = $this->docxWithInline('{{?legacy}}x{{/?legacy}}');

        try {
            $parsed = $this->invokeParse($tpl);

            self::assertArrayNotHasKey('segments', $parsed);
        } finally {
            @unlink($tpl);
        }
    }

    public function test_inline_region_kept_when_field_present(): void
    {
        $tpl = $this->docxWithInline('Основа: {{base}}. {{note?}}Примечание: {{note}} — учтено.{{/note?}} Конец.');

        try {
            $text = $this->render($tpl, new RenderData([
                'base' => new TextValue('Цинкол'),
                'note' => new TextValue('скол на кромке'),
            ]));

            self::assertStringContainsString('Примечание: скол на кромке — учтено.', $text);
            self::assertStringContainsString('Конец.', $text);
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($tpl);
        }
    }

    public function test_inline_region_removed_when_field_absent(): void
    {
        $tpl = $this->docxWithInline('Основа: {{base}}. {{note?}}Примечание: {{note}} — учтено.{{/note?}} Конец.');

        try {
            $text = $this->render($tpl, new RenderData(['base' => new TextValue('Цинкол')]));

            self::assertStringNotContainsString('Примечание', $text);
            self::assertStringNotContainsString('учтено', $text);
            self::assertStringContainsString('Основа: Цинкол.', $text);
            self::assertStringContainsString('Конец.', $text);
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($tpl);
        }
    }

    /**
     * Два независимых инлайн-региона (разные логические имена) в разных абзацах не путают друг друга —
     * presence одного не влияет на другой (симметрично старому test_same_subfield_name_in_two_groups_not_confused,
     * но на верхнеуровневых именах вместо подполей повторяемой строки).
     */
    public function test_multiple_inline_regions_do_not_cross_contaminate(): void
    {
        $tpl = $this->docxWithParagraphs([
            '{{inst_a}}{{sensor_a?}}, датчик {{sensor_a}}{{/sensor_a?}}',
            '{{inst_b}}{{sensor_b?}}, датчик {{sensor_b}}{{/sensor_b?}}',
        ]);

        try {
            $text = $this->render($tpl, new RenderData([
                'inst_a' => new TextValue('Прибор1'),
                'inst_b' => new TextValue('Прибор2'),
                'sensor_a' => new TextValue('Elcometer 456'), // sensor_b отсутствует
            ]));

            self::assertStringContainsString('Прибор1, датчик Elcometer 456', $text);
            self::assertStringContainsString('Прибор2', $text);
            self::assertSame(1, substr_count($text, 'датчик'), 'слово «датчик» только у региона с данными');
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($tpl);
        }
    }
}
