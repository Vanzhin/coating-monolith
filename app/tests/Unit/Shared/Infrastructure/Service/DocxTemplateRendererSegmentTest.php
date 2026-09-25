<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Инлайновые опциональные сегменты {{?name}}…{{/?name}}: вырезают литерал вместе с плейсхолдером, когда
 * данных нет. Верхний уровень (плоское поле / присутствие группы) и внутри повторяемой строки (подполе).
 */
final class DocxTemplateRendererSegmentTest extends TestCase
{
    public function test_top_level_segment_kept_when_field_present(): void
    {
        $path = $this->docx(static function (PhpWord $w): void {
            $s = $w->addSection();
            $s->addText('Основа: {{base}}. {{?note}}Примечание: {{note}} — учтено.{{/?note}} Конец.');
        });

        try {
            $text = $this->render($path, new RenderData([
                'base' => new TextValue('Цинкол'),
                'note' => new TextValue('скол на кромке'),
            ]));

            self::assertStringContainsString('Примечание: скол на кромке — учтено.', $text);
            self::assertStringContainsString('Конец.', $text);
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($path);
        }
    }

    public function test_top_level_segment_removed_when_field_absent(): void
    {
        $path = $this->docx(static function (PhpWord $w): void {
            $s = $w->addSection();
            $s->addText('Основа: {{base}}. {{?note}}Примечание: {{note}} — учтено.{{/?note}} Конец.');
        });

        try {
            $text = $this->render($path, new RenderData(['base' => new TextValue('Цинкол')]));

            self::assertStringNotContainsString('Примечание', $text);
            self::assertStringNotContainsString('учтено', $text);
            self::assertStringContainsString('Основа: Цинкол.', $text);
            self::assertStringContainsString('Конец.', $text);
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($path);
        }
    }

    public function test_row_segment_hidden_only_where_subfield_empty(): void
    {
        $path = $this->rowTemplate();

        try {
            $text = $this->render($path, new RenderData(['inst' => new RepeatValue([
                ['name' => 'Прибор1', 'sensor' => 'Elcometer 456'],
                ['name' => 'Прибор2', 'sensor' => ''],
            ])]));

            self::assertStringContainsString('Прибор1, датчик Elcometer 456', $text);
            self::assertStringContainsString('Прибор2', $text);
            self::assertSame(1, substr_count($text, 'датчик'), 'слово «датчик» только у строки с датчиком');
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($path);
        }
    }

    public function test_row_segment_all_present_and_all_empty(): void
    {
        $path = $this->rowTemplate();

        try {
            $all = $this->render($path, new RenderData(['inst' => new RepeatValue([
                ['name' => 'A', 'sensor' => 'S1'],
                ['name' => 'B', 'sensor' => 'S2'],
            ])]));
            self::assertSame(2, substr_count($all, 'датчик'));

            $none = $this->render($path, new RenderData(['inst' => new RepeatValue([
                ['name' => 'A', 'sensor' => ''],
                ['name' => 'B', 'sensor' => ''],
            ])]));
            self::assertStringNotContainsString('датчик', $none);
            self::assertStringContainsString('A', $none);
            self::assertStringContainsString('B', $none);
        } finally {
            @unlink($path);
        }
    }

    public function test_same_subfield_name_in_two_groups_not_confused(): void
    {
        $path = $this->docx(static function (PhpWord $w): void {
            $s = $w->addSection();
            $t1 = $s->addTable();
            $t1->addRow();
            $t1->addCell()->addText('{{inst.name}}{{?sensor}} + датчик {{inst.sensor}}{{/?sensor}}');
            $t2 = $s->addTable();
            $t2->addRow();
            $t2->addCell()->addText('{{dev.name}}{{?sensor}} + датчик {{dev.sensor}}{{/?sensor}}');
        });

        try {
            $text = $this->render($path, new RenderData([
                'inst' => new RepeatValue([['name' => 'Первый', 'sensor' => 'Есть']]),
                'dev' => new RepeatValue([['name' => 'Второй', 'sensor' => '']]),
            ]));

            self::assertStringContainsString('Первый + датчик Есть', $text);
            self::assertStringContainsString('Второй', $text);
            self::assertSame(1, substr_count($text, 'датчик'), 'вторая группа с пустым подполем не показывает датчик');
        } finally {
            @unlink($path);
        }
    }

    public function test_row_segment_without_placeholder_keyed_on_subfield(): void
    {
        $path = $this->docx(static function (PhpWord $w): void {
            $s = $w->addSection();
            $t = $s->addTable();
            $t->addRow();
            $t->addCell()->addText('{{inst.name}}{{?critical}} (КРИТИЧНО){{/?critical}}');
        });

        try {
            $text = $this->render($path, new RenderData(['inst' => new RepeatValue([
                ['name' => 'Датчик A', 'critical' => '1'],
                ['name' => 'Датчик B', 'critical' => ''],
            ])]));

            self::assertStringContainsString('Датчик A (КРИТИЧНО)', $text);
            self::assertStringContainsString('Датчик B', $text);
            self::assertSame(1, substr_count($text, 'КРИТИЧНО'));
        } finally {
            @unlink($path);
        }
    }

    public function test_segment_wraps_heading_and_repeat_block(): void
    {
        $make = static function (): string {
            $w = new PhpWord();
            $s = $w->addSection();
            $s->addText('{{?process}}');
            $s->addText('Выявленные несоответствия:');
            $s->addText('{{process}}');
            $s->addText('{{process.description}}');
            $s->addText('{{/process}}');
            $s->addText('{{/?process}}');
            $s->addText('Подпись.');
            $path = sys_get_temp_dir().'/seg_block_'.uniqid().'.docx';
            WordIO::createWriter($w, 'Word2007')->save($path);

            return $path;
        };

        $withRows = $make();
        try {
            $text = $this->render($withRows, new RenderData(['process' => new RepeatValue([
                ['description' => 'Скол ЛКП'],
                ['description' => 'Потёк на шве'],
            ])]));
            self::assertSame(1, substr_count($text, 'Выявленные несоответствия:'), 'заголовок один раз');
            self::assertStringContainsString('Скол ЛКП', $text);
            self::assertStringContainsString('Потёк на шве', $text);
            self::assertStringContainsString('Подпись.', $text);
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($withRows);
        }
    }

    /**
     * Регресс на изменение поведения (соседняя задача с этим тестовым файлом): внутренний блок-повтор тут
     * СТРОГИЙ ({{process}}…{{/process}}, без `?`) — раньше пустой список молча удалял регион (заголовок
     * снаружи снимал инлайн-сегмент {{?process}}); теперь строгий пустой повтор уходит в missing и
     * блокирует сборку файла целиком — единообразно с DocxTemplateRendererOptionalityTest
     * ::test_required_empty_region_is_missing и DocxTemplateRendererRepeatTest
     * ::test_empty_list_strict_block_is_missing_and_blocks_render. Чтобы вернуть прежнее «заголовок тихо
     * исчезает» — автор шаблона помечает внутренний блок опциональным ({{process?}}…{{/process?}}); см.
     * DocxTemplateRendererRepeatTest::test_empty_optional_block_repeat_is_skipped_not_missing (полный
     * render() опционального БЛОЧНОГО региона там же проверяется).
     */
    public function test_segment_with_empty_strict_repeat_block_blocks_render(): void
    {
        $w = new PhpWord();
        $s = $w->addSection();
        $s->addText('{{?process}}');
        $s->addText('Выявленные несоответствия:');
        $s->addText('{{process}}');
        $s->addText('{{process.description}}');
        $s->addText('{{/process}}');
        $s->addText('{{/?process}}');
        $s->addText('Подпись.');
        $path = sys_get_temp_dir().'/seg_block_empty_'.uniqid().'.docx';
        WordIO::createWriter($w, 'Word2007')->save($path);

        try {
            $result = (new DocxTemplateRenderer())->validate(new TemplateFile($path), new RenderData(['process' => new RepeatValue([])]));
            self::assertContains('process', $result->missing);
            self::assertFalse($result->isValid());
        } finally {
            @unlink($path);
        }
    }

    private function rowTemplate(): string
    {
        return $this->docx(static function (PhpWord $w): void {
            $s = $w->addSection();
            $t = $s->addTable();
            $t->addRow();
            $t->addCell()->addText('{{inst.name}}{{?sensor}}, датчик {{inst.sensor}}{{/?sensor}}');
        });
    }

    /**
     * @param callable(PhpWord): void $build
     */
    private function docx(callable $build): string
    {
        $w = new PhpWord();
        $build($w);
        $path = sys_get_temp_dir().'/seg_tpl_'.uniqid().'.docx';
        WordIO::createWriter($w, 'Word2007')->save($path);

        return $path;
    }

    private function render(string $templatePath, RenderData $data): string
    {
        $doc = (new DocxTemplateRenderer())->render(new TemplateFile($templatePath), $data);
        $out = sys_get_temp_dir().'/seg_out_'.uniqid().'.docx';
        file_put_contents($out, $doc->content);
        $zip = new \ZipArchive();
        $zip->open($out);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($out);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $xml))));
    }
}
