<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Повторяемая строка таблицы: {{c.a}}|{{c.b}} → клонируется по числу записей и заполняется; пустой
 * список → строка-шаблон удаляется, шапка таблицы остаётся.
 */
final class DocxTemplateRendererRepeatTest extends TestCase
{
    private string $templatePath;

    protected function setUp(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('Орг');
        $table->addCell()->addText('ФИО');
        $table->addRow();
        $table->addCell()->addText('{{c.a}}');
        $table->addCell()->addText('{{c.b}}');

        $this->templatePath = sys_get_temp_dir().'/repeat_tpl_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($this->templatePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
    }

    public function test_repeats_row_per_record(): void
    {
        $data = new RenderData(['c' => new RepeatValue([
            ['a' => 'ЗМК Наста', 'b' => 'Иванов И.И.'],
            ['a' => 'Литум', 'b' => 'Петров П.П.'],
        ])]);

        $text = $this->render($data);

        self::assertStringContainsString('ЗМК Наста', $text);
        self::assertStringContainsString('Иванов И.И.', $text);
        self::assertStringContainsString('Литум', $text);
        self::assertStringContainsString('Петров П.П.', $text);
        self::assertStringNotContainsString('{{c.a}}', $text);
        self::assertStringContainsString('Орг', $text); // шапка на месте
    }

    public function test_empty_list_deletes_template_row_keeps_header(): void
    {
        $text = $this->render(new RenderData(['c' => new RepeatValue([])]));

        self::assertStringContainsString('Орг', $text);
        self::assertStringContainsString('ФИО', $text);
        self::assertStringNotContainsString('{{c.a}}', $text);
        self::assertStringNotContainsString('{{c.b}}', $text);
    }

    public function test_repeats_list_block_per_item(): void
    {
        $path = $this->blockTemplate();
        try {
            $text = $this->renderTpl($path, new RenderData(['recs' => new RepeatValue([
                ['text' => 'Промыть пресной водой'],
                ['text' => 'Контроль ТСП через 24 ч'],
            ])]));

            self::assertStringContainsString('Промыть пресной водой', $text);
            self::assertStringContainsString('Контроль ТСП через 24 ч', $text);
            self::assertStringNotContainsString('{{recs', $text); // маркеры и плейсхолдер убраны
        } finally {
            @unlink($path);
        }
    }

    public function test_empty_list_block_deletes_region(): void
    {
        $path = $this->blockTemplate();
        try {
            $text = $this->renderTpl($path, new RenderData(['recs' => new RepeatValue([])]));

            self::assertStringContainsString('Заголовок раздела', $text); // текст вне блока цел
            self::assertStringNotContainsString('{{recs', $text);
        } finally {
            @unlink($path);
        }
    }

    private function blockTemplate(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Заголовок раздела');
        $section->addText('{{recs}}');
        $section->addText('{{recs.text}}');
        $section->addText('{{/recs}}');

        $path = sys_get_temp_dir().'/repeat_block_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function render(RenderData $data): string
    {
        return $this->renderTpl($this->templatePath, $data);
    }

    private function renderTpl(string $templatePath, RenderData $data): string
    {
        $doc = (new DocxTemplateRenderer())->render(new TemplateFile($templatePath), $data);
        $path = sys_get_temp_dir().'/repeat_out_'.uniqid().'.docx';
        file_put_contents($path, $doc->content);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $xml))));
    }
}
