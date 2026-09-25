<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

final class DocxTemplateRendererTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $templatePath;
    private string $imagePath;
    private DocxTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Объект: {{object}}');
        $section->addText('Основа: {{base}}');
        $section->addText('{{opt_comp_b}}');
        $section->addText('Комп. Б № партии {{comp_b_batch}}');
        $section->addText('{{/opt_comp_b}}');
        $section->addText('Комментарий: {{comment?}}');
        $section->addText('{{photo?}}');

        $this->templatePath = sys_get_temp_dir().'/tpl_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($this->templatePath);

        $this->imagePath = sys_get_temp_dir().'/img_'.uniqid().'.png';
        file_put_contents($this->imagePath, base64_decode(self::PNG_1X1));

        $this->renderer = new DocxTemplateRenderer();
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
        @unlink($this->imagePath);
    }

    private function template(): TemplateFile
    {
        return new TemplateFile($this->templatePath);
    }

    public function test_variables_flags_and_excludes_block_markers(): void
    {
        $byName = [];
        foreach ($this->renderer->variables($this->template()) as $variable) {
            $byName[$variable->name] = $variable->optional;
        }

        self::assertArrayNotHasKey('opt_comp_b', $byName);
        self::assertArrayNotHasKey('/opt_comp_b', $byName);
        self::assertFalse($byName['object']);
        self::assertFalse($byName['base']);
        self::assertTrue($byName['comment'], 'помечен ? — опциональный');
        self::assertTrue($byName['photo'], 'помечен ? — опциональный');
        self::assertTrue($byName['comp_b_batch'], 'внутри блока — опциональный');
    }

    public function test_validate_two_component_is_valid(): void
    {
        $result = $this->renderer->validate($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
            'base' => new TextValue('Литамастик 290'),
            'comp_b_batch' => new TextValue('LO00-4059'),
        ]));

        self::assertSame([], $result->missing);
        self::assertTrue($result->isValid());
    }

    /**
     * `{{opt_comp_b}}…{{/opt_comp_b}}` — старое (до `?`-синтаксиса) наименование блока, литерально БЕЗ `?`.
     * Под новым парсером это СТРОГИЙ регион: пустой блок больше не тихо выпадает, а уходит в missing и
     * блокирует сборку файла — единообразно с DocxTemplateRendererOptionalityTest
     * ::test_required_empty_region_is_missing.
     */
    public function test_validate_strict_block_empty_is_missing(): void
    {
        $result = $this->renderer->validate($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
            'base' => new TextValue('Цинкол'),
        ]));

        self::assertContains('opt_comp_b', $result->missing);
        self::assertFalse($result->isValid());
    }

    public function test_validate_missing_required(): void
    {
        $result = $this->renderer->validate($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
        ]));

        self::assertSame(['opt_comp_b', 'base'], $result->missing);
        self::assertFalse($result->isValid());
    }

    public function test_render_two_component_keeps_block(): void
    {
        $doc = $this->renderer->render($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
            'base' => new TextValue('Литамастик 290'),
            'comp_b_batch' => new TextValue('LO00-4059'),
        ]));

        $text = $this->docxText($doc->content);
        self::assertStringContainsString('Комп. Б № партии LO00-4059', $text);
        self::assertStringContainsString('Объект: Балка Б2-3', $text);
        self::assertSame([], $this->leftoverVariables($doc->content));
    }

    /**
     * Раньше пустой `opt_comp_b` молча удалял блок; теперь строгий (без `?`) пустой блок блокирует
     * render() целиком — см. комментарий у test_validate_strict_block_empty_is_missing.
     */
    public function test_render_throws_when_strict_block_field_missing(): void
    {
        $this->expectException(AppException::class);

        $this->renderer->render($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
            'base' => new TextValue('Цинкол'),
        ]));
    }

    public function test_render_strict_throws_on_missing_required(): void
    {
        $this->expectException(AppException::class);

        $this->renderer->render($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
        ]));
    }

    public function test_render_blanks_absent_optional(): void
    {
        // comp_b_batch заполнен нарочно — блок opt_comp_b СТРОГИЙ (без ?), пустым он заблокировал бы
        // render(); здесь проверяем именно опциональный СКАЛЯР {{comment?}}, а не блок.
        $doc = $this->renderer->render($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
            'base' => new TextValue('Цинкол'),
            'comp_b_batch' => new TextValue('LO00-4059'),
        ]));

        $text = $this->docxText($doc->content);
        self::assertStringContainsString('Комментарий:', $text);
        self::assertSame([], $this->leftoverVariables($doc->content));
    }

    public function test_render_inserts_image_media(): void
    {
        // comp_b_batch заполнен нарочно — см. комментарий в test_render_blanks_absent_optional.
        $doc = $this->renderer->render($this->template(), new RenderData([
            'object' => new TextValue('Балка Б2-3'),
            'base' => new TextValue('Цинкол'),
            'comp_b_batch' => new TextValue('LO00-4059'),
            'photo' => new ImageValue($this->imagePath),
        ]));

        self::assertTrue($this->docxHasMedia($doc->content), 'ожидается word/media/* в выходном docx');
        self::assertSame([], $this->leftoverVariables($doc->content));
    }

    public function test_discovers_and_fills_header_placeholder(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addHeader()->addText('АКТ № {{act_no}}');
        $section->addText('Объект: {{object}}');

        $path = sys_get_temp_dir().'/tpl_header_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($path);

        try {
            $names = array_map(static fn ($v) => $v->name, $this->renderer->variables(new TemplateFile($path)));
            self::assertContains('act_no', $names, 'плейсхолдер колонтитула должен обнаруживаться');

            $doc = $this->renderer->render(new TemplateFile($path), new RenderData([
                'act_no' => new TextValue('01-05-08-2026'),
                'object' => new TextValue('Балка Б2-3'),
            ]));

            self::assertSame([], $this->leftoverVariables($doc->content), 'в колонтитуле не должно остаться {{...}}');
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function leftoverVariables(string $bytes): array
    {
        $path = $this->writeTemp($bytes);
        $names = [];
        foreach ($this->renderer->variables(new TemplateFile($path)) as $variable) {
            $names[] = $variable->name;
        }
        @unlink($path);

        return $names;
    }

    private function docxText(string $bytes): string
    {
        $path = $this->writeTemp($bytes);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $text = strip_tags(str_replace('<', ' <', $xml));

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function docxHasMedia(string $bytes): bool
    {
        $path = $this->writeTemp($bytes);
        $zip = new \ZipArchive();
        $zip->open($path);
        $found = false;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'word/media/')) {
                $found = true;
                break;
            }
        }
        $zip->close();
        @unlink($path);

        return $found;
    }

    private function writeTemp(string $bytes): string
    {
        $path = sys_get_temp_dir().'/out_'.uniqid().'.docx';
        file_put_contents($path, $bytes);

        return $path;
    }
}
