<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;

/**
 * Общие хелперы юнит-тестов docx-движка: собрать .docx из списка абзацев и распарсить его через
 * приватный DocxTemplateRenderer::parse() рефлексией (для тестов структуры парсинга, без полного render()).
 */
trait DocxFixtureTrait
{
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

        $path = sys_get_temp_dir().'/docx_tpl_'.uniqid().'.docx';
        WordIO::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Один абзац с несколькими токенами (плейсхолдер + инлайн-регион в одной строке/ячейке) — в отличие
     * от docxWithParagraphs(), где каждый элемент списка становится СВОИМ абзацем.
     */
    private function docxWithInline(string $paragraph): string
    {
        return $this->docxWithParagraphs([$paragraph]);
    }

    /**
     * @return array{
     *     values: list<array{logical: string, token: string, optional: bool, block: string|null}>,
     *     blocks: array<string, array{optional: bool, values: list<string>}>,
     *     repeats: array<string, array{subs: list<string>, anchor: string}>,
     *     segments: list<string>,
     *     inlineRegions: list<string>
     * }
     */
    private function invokeParse(string $templatePath): array
    {
        $renderer = new DocxTemplateRenderer();
        $rendererReflection = new \ReflectionClass($renderer);

        $loadMethod = $rendererReflection->getMethod('load');
        $processor = $loadMethod->invoke($renderer, new TemplateFile($templatePath));

        $parseMethod = $rendererReflection->getMethod('parse');

        /** @var array{values: list<array{logical: string, token: string, optional: bool, block: string|null}>, blocks: array<string, array{optional: bool, values: list<string>}>, repeats: array<string, array{subs: list<string>, anchor: string}>, segments: list<string>, inlineRegions: list<string>} $parsed */
        $parsed = $parseMethod->invoke($renderer, $processor);

        return $parsed;
    }

    /**
     * Полный render() + вытянуть текст word/document.xml (без тегов, схлопнутые пробелы) — для тестов,
     * проверяющих итоговое содержимое документа (не только структуру parse()).
     */
    private function render(string $templatePath, RenderData $data): string
    {
        $doc = (new DocxTemplateRenderer())->render(new TemplateFile($templatePath), $data);

        $outPath = sys_get_temp_dir().'/docx_out_'.uniqid().'.docx';
        file_put_contents($outPath, $doc->content);

        $zip = new \ZipArchive();
        $zip->open($outPath);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($outPath);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $xml))));
    }
}
