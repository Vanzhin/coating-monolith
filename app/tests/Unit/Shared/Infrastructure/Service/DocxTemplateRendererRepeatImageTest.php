<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Повтор, несущий КАРТИНКИ (фото отчёта): опц. регион {{photos?}}…{{/photos?}} с подполями
 * {{photos.image}} (ImageValue) + {{photos.caption}} (текст). Движок должен вставить каждую картинку
 * в клон блока (word/media/) и залить подпись; пустой список → регион исчезает, файла-мусора нет.
 */
final class DocxTemplateRendererRepeatImageTest extends TestCase
{
    use DocxFixtureTrait;

    /** @var list<string> */
    private array $pngs = [];

    protected function tearDown(): void
    {
        foreach ($this->pngs as $p) {
            @unlink($p);
        }
    }

    public function test_repeat_embeds_image_per_row_and_fills_caption(): void
    {
        $tpl = $this->docxWithParagraphs([
            'Фотоотчёт:',
            '{{photos?}}',
            '{{photos.image}}',
            'Подпись: {{photos.caption}}',
            '{{/photos?}}',
            'Конец.',
        ]);

        $data = new RenderData(['photos' => new RepeatValue([
            ['image' => new ImageValue($this->png(200, 60, 40)), 'caption' => 'Балка Б-1'],
            ['image' => new ImageValue($this->png(40, 60, 200)), 'caption' => 'Опора О-2'],
        ])]);

        [$xml, $mediaCount] = $this->renderAndInspect($tpl, $data);
        @unlink($tpl);

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $xml))));
        self::assertSame(2, $mediaCount, 'обе картинки должны попасть в word/media/');
        self::assertStringContainsString('Балка Б-1', $text);
        self::assertStringContainsString('Опора О-2', $text);
        self::assertStringContainsString('Конец.', $text); // контент после региона цел
        self::assertStringNotContainsString('{{', $text);
    }

    public function test_empty_photos_removes_region_no_leftover(): void
    {
        $tpl = $this->docxWithParagraphs([
            'Фотоотчёт:', '{{photos?}}', '{{photos.image}}', 'Подпись: {{photos.caption}}', '{{/photos?}}', 'Конец.',
        ]);

        [$xml, $mediaCount] = $this->renderAndInspect($tpl, new RenderData(['photos' => new RepeatValue([])]));
        @unlink($tpl);

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $xml))));
        self::assertSame(0, $mediaCount);
        self::assertStringContainsString('Конец.', $text);
        self::assertStringNotContainsString('Подпись:', $text); // регион с подписью исчез
        self::assertStringNotContainsString('{{', $text);
    }

    /**
     * @return array{0: string, 1: int} document.xml и число файлов в word/media/
     */
    private function renderAndInspect(string $tpl, RenderData $data): array
    {
        $doc = (new DocxTemplateRenderer())->render(new TemplateFile($tpl), $data);
        $out = sys_get_temp_dir().'/photo_out_'.uniqid().'.docx';
        file_put_contents($out, $doc->content);

        $zip = new \ZipArchive();
        $zip->open($out);
        $xml = (string) $zip->getFromName('word/document.xml');
        $media = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'word/media/')) {
                ++$media;
            }
        }
        $zip->close();
        @unlink($out);

        return [$xml, $media];
    }

    private function png(int $r, int $g, int $b): string
    {
        $path = sys_get_temp_dir().'/ph_'.uniqid().'.png';
        $im = imagecreatetruecolor(24, 24);
        imagefilledrectangle($im, 0, 0, 23, 23, imagecolorallocate($im, $r, $g, $b));
        imagepng($im, $path);
        $this->pngs[] = $path;

        return $path;
    }
}
