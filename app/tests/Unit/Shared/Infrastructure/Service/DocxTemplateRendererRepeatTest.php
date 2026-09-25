<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Повторяемая строка таблицы: {{c.a}}|{{c.b}} → клонируется по числу записей и заполняется; пустой
 * список → строка-шаблон удаляется, шапка таблицы остаётся (опциональна по природе — маркеров у строки
 * нет, ставить `?` некуда). Повтор-блок {{group}}…{{/group}}: пустой список у строгого блока → missing
 * (файл не собрать), у опционального ({{group?}}…{{/group?}}) → тихо skipped (см. блок тестов ниже).
 */
final class DocxTemplateRendererRepeatTest extends TestCase
{
    use DocxFixtureTrait;

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

    public function test_same_group_in_two_tables_both_filled(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        foreach (['Начало', 'Конец'] as $marker) {
            $section->addText($marker);
            $t = $section->addTable();
            $t->addRow();
            $t->addCell()->addText('{{c.a}}');
            $t->addCell()->addText('{{c.b}}');
        }
        $path = sys_get_temp_dir().'/repeat_two_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($path);

        try {
            $text = $this->renderTpl($path, new RenderData(['c' => new RepeatValue([
                ['a' => 'Иванов', 'b' => 'Инженер'],
                ['a' => 'Петров', 'b' => 'Мастер'],
            ])]));

            // Обе таблицы (начало и конец) заполнены обеими записями.
            self::assertSame(2, substr_count($text, 'Иванов'), 'Иванов в обеих таблицах');
            self::assertSame(2, substr_count($text, 'Петров'), 'Петров в обеих таблицах');
            self::assertStringNotContainsString('{{c.a}}', $text);
        } finally {
            @unlink($path);
        }
    }

    public function test_special_chars_are_escaped_and_document_valid(): void
    {
        $text = $this->render(new RenderData(['c' => new RepeatValue([
            ['a' => 'ООО "Ромашка" & Партнёры', 'b' => 't < 5 > 0'],
        ])]));

        // Документ валиден (не битый XML) и содержит текст (экранирование сохранило смысл).
        self::assertStringContainsString('Ромашка', $text);
        self::assertStringContainsString('Партнёры', $text);
    }

    public function test_raw_ampersand_keeps_document_well_formed(): void
    {
        // Проверяем именно валидность word/document.xml (сырой & без экранирования его ломает).
        $doc = (new DocxTemplateRenderer())->render(
            new TemplateFile($this->templatePath),
            new RenderData(['c' => new RepeatValue([['a' => 'A & B', 'b' => '<x>']])]),
        );
        $path = sys_get_temp_dir().'/esc_'.uniqid().'.docx';
        file_put_contents($path, $doc->content);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $prev = libxml_use_internal_errors(true);
        $ok = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        self::assertNotFalse($ok, 'word/document.xml должен быть валидным XML при & и < в данных');
    }

    public function test_question_mark_on_repeat_member_is_normalized(): void
    {
        // {{c.a?}} — `?` у точечного токена бессмысленен; должен обработаться как {{c.a}}, а не остаться сырым.
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $t = $section->addTable();
        $t->addRow();
        $t->addCell()->addText('{{c.a?}}');
        $t->addCell()->addText('{{c.b}}');
        $path = sys_get_temp_dir().'/qm_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($path);

        try {
            $text = $this->renderTpl($path, new RenderData(['c' => new RepeatValue([
                ['a' => 'Иванов', 'b' => 'Инженер'],
            ])]));
            self::assertStringContainsString('Иванов', $text);
            self::assertStringNotContainsString('{{c.a', $text); // сырого токена не осталось
        } finally {
            @unlink($path);
        }
    }

    public function test_repeat_placeholder_in_header_does_not_crash(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addHeader()->addText('{{c.a}}'); // точечный токен в колонтитуле — не поддержан, но НЕ должен ронять
        $t = $section->addTable();
        $t->addRow();
        $t->addCell()->addText('{{c.a}}');
        $t->addCell()->addText('{{c.b}}');
        $path = sys_get_temp_dir().'/hdr_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($path);

        try {
            $text = $this->renderTpl($path, new RenderData(['c' => new RepeatValue([
                ['a' => 'Иванов', 'b' => 'Инженер'],
            ])]));
            // Тело-таблица заполнилась, исключения нет (колонтитул не свалил в 500).
            self::assertStringContainsString('Иванов', $text);
        } finally {
            @unlink($path);
        }
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

    /**
     * Регресс на изменение поведения: раньше пустой список у СТРОГОГО ({{recs}}…{{/recs}}, без `?`)
     * блока-повтора молча удалял регион; теперь строгий пустой повтор — как строгий пустой регион у
     * одиночного плейсхолдера — уходит в missing и блокирует сборку файла (см.
     * DocxTemplateRendererOptionalityTest::test_required_empty_region_is_missing — тот же контракт).
     */
    public function test_empty_list_strict_block_is_missing_and_blocks_render(): void
    {
        $path = $this->blockTemplate();
        try {
            $result = (new DocxTemplateRenderer())->validate(new TemplateFile($path), new RenderData(['recs' => new RepeatValue([])]));
            self::assertContains('recs', $result->missing);
            self::assertFalse($result->isValid());

            $this->expectException(AppException::class);
            (new DocxTemplateRenderer())->render(new TemplateFile($path), new RenderData(['recs' => new RepeatValue([])]));
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_data_key_for_strict_block_repeat_is_missing(): void
    {
        $path = $this->blockTemplate();

        try {
            $result = (new DocxTemplateRenderer())->validate(new TemplateFile($path), new RenderData([])); // ключа 'recs' вовсе нет
            self::assertContains('recs', $result->missing);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Опциональный ({{recs?}}…{{/recs?}}) блок-повтор с пустым списком — validate() уходит в skipped, не
     * missing (файл собрать МОЖНО), и render() реально собирает файл: регион уходит целиком, контент после
     * него цел (cloneBlock/deleteBlock матчат литерал маркера `recs?`, не логическое имя `recs`).
     */
    public function test_empty_optional_block_repeat_is_skipped_not_missing(): void
    {
        $path = $this->docxWithParagraphs(['Заголовок раздела', '{{recs?}}', '{{recs.text}}', '{{/recs?}}', 'После.']);

        try {
            $result = (new DocxTemplateRenderer())->validate(new TemplateFile($path), new RenderData(['recs' => new RepeatValue([])]));
            self::assertNotContains('recs', $result->missing);
            self::assertContains('recs', $result->skipped);
            self::assertTrue($result->isValid());

            $text = $this->renderTpl($path, new RenderData(['recs' => new RepeatValue([])]));
            self::assertStringContainsString('Заголовок раздела', $text);
            self::assertStringContainsString('После.', $text); // контент после региона не обрублен
            self::assertStringNotContainsString('{{', $text);
        } finally {
            @unlink($path);
        }
    }

    private function blockTemplate(): string
    {
        return $this->docxWithParagraphs(['Заголовок раздела', '{{recs}}', '{{recs.text}}', '{{/recs}}']);
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
