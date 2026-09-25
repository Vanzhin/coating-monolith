<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Инлайн-регион ВНУТРИ строки повтора на точечном имени: `{{group.sub?}}…{{/group.sub?}}`. Прячет
 * сопроводительный текст рядом с подполем строки ПО-СТРОЧНО: у прибора с датчиком «, датчик X» остаётся,
 * у прибора без датчика вырезается целиком вместе с литералом. Регрессия: раньше это умел префикс-сегмент
 * `{{?sensor}}`; унификация его убрала, а новый `{{x?}}` в строке повтора ломался — cloneRow проставлял
 * маркерам региона суффикс `#i`, и резолвер их не находил (leftover → «не заполнены плейсхолдеры»).
 */
final class DocxTemplateRendererRowInlineRegionTest extends TestCase
{
    use DocxFixtureTrait;

    private string $templatePath;

    protected function setUp(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('Приборы');
        $table->addRow();
        $table->addCell()->addText('{{instruments.name}}, серийный №{{instruments.serial}}{{instruments.sensor?}}, датчик {{instruments.sensor}}{{/instruments.sensor?}};');

        $this->templatePath = sys_get_temp_dir().'/rowinline_tpl_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($this->templatePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
    }

    public function test_row_inline_region_kept_where_subfield_present_cut_where_empty(): void
    {
        $data = new RenderData(['instruments' => new RepeatValue([
            ['name' => 'Толщиномер', 'serial' => 'A-1', 'sensor' => 'Ф-2'],
            ['name' => 'Гигрометр', 'serial' => 'B-9', 'sensor' => ''],
        ])]);

        $text = $this->render($this->templatePath, $data);

        // у прибора с датчиком — сопроводительный текст остался
        self::assertStringContainsString('датчик Ф-2', $text);
        // ровно один прибор показывает «датчик» (у второго регион вырезан целиком)
        self::assertSame(1, substr_count($text, 'датчик'));
        // оба прибора на месте
        self::assertStringContainsString('Толщиномер', $text);
        self::assertStringContainsString('Гигрометр', $text);
        // никаких сырых/индексированных маркеров региона не осталось
        self::assertStringNotContainsString('{{', $text);
        self::assertStringNotContainsString('sensor', $text);
    }

    public function test_empty_list_removes_row_no_leftover(): void
    {
        $text = $this->render($this->templatePath, new RenderData(['instruments' => new RepeatValue([])]));

        self::assertStringContainsString('Приборы', $text); // шапка таблицы
        self::assertStringNotContainsString('{{', $text);
        self::assertStringNotContainsString('датчик', $text);
    }
}
