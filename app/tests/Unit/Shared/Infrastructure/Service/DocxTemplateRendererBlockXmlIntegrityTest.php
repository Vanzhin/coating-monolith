<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Целостность XML при удалении опционального блока на шаблоне «как из Word»: абзацы со СТИЛЕМ несут
 * `<w:pPr>` (свойства абзаца). Родительский PhpWord `deleteBlock` строит жадный regex `<w:p.*>`, который
 * матчит и `<w:pPr>` (общий префикс `<w:p`) → удаление стартует внутри абзаца, оставляет осиротевший
 * `<w:p>` → невалидный word/document.xml (Word чинит молча, LibreOffice/строгие парсеры отвергают
 * «документ повреждён»). Проверка по тексту (strip_tags) этого НЕ ловит — нужен парс сырого XML.
 * Наш DocxMacroProcessor::deleteBlock делегирует в cloneBlock(0) (regex с `<w:p\b`), поэтому XML остаётся
 * well-formed. Регресс на баг «Акт обрывается на заголовке блока».
 */
final class DocxTemplateRendererBlockXmlIntegrityTest extends TestCase
{
    use DocxFixtureTrait;

    public function test_deleting_empty_optional_block_with_paragraph_props_keeps_xml_well_formed(): void
    {
        $tpl = $this->styledBlockTemplate();

        // process пуст → блок {{process?}}…{{/process?}} удаляется; recommendations с данными → клонируется.
        $xml = $this->renderRawMainXml($tpl, new RenderData([
            'process' => new RepeatValue([]),
            'recommendations' => new RepeatValue([['text' => 'Проветривать']]),
        ]));
        @unlink($tpl);

        // 1) XML строго well-formed (главная проверка — именно её проваливал баг)
        $prev = libxml_use_internal_errors(true);
        $ok = false !== simplexml_load_string($xml);
        $err = $ok ? '' : (string) (libxml_get_last_error()->message ?? '');
        libxml_use_internal_errors($prev);
        self::assertTrue($ok, 'word/document.xml должен быть well-formed после удаления блока: '.$err);

        // 2) баланс абзацев не поехал
        self::assertSame(
            preg_match_all('/<w:p[ >]/', $xml),
            preg_match_all('#</w:p>#', $xml),
            '<w:p> должны быть сбалансированы',
        );

        // 3) контент ПОСЛЕ удалённого блока выжил, маркеры сняты
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $xml))));
        self::assertStringContainsString('Проветривать', $text);   // блок recommendations цел
        self::assertStringContainsString('Выводы', $text);          // всё после process не обрезано
        self::assertStringNotContainsString('{{', $text);
    }

    /**
     * Шаблон с абзацами СО стилем (принудительно даёт `<w:pPr>`, как реальный Word-документ): опциональный
     * блок-повтор {{process?}}, за ним ещё блок и хвост.
     */
    private function styledBlockTemplate(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $paragraphStyle = ['spaceAfter' => 120, 'alignment' => 'both'];
        foreach ([
            'Процесс выполнения работ:',
            '{{process?}}',
            '    1. {{process.description}}',
            '{{/process?}}',
            'Рекомендации:',
            '{{recommendations?}}',
            '    1. {{recommendations.text}}',
            '{{/recommendations?}}',
            'Выводы и заключение комиссии.',
        ] as $line) {
            $section->addText($line, null, $paragraphStyle);
        }

        $path = sys_get_temp_dir().'/styled_block_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }
}
