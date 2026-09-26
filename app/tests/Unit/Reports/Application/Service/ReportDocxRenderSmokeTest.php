<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Application\Service;

use App\Reports\Application\Service\Mapping\ReportTemplateMap;
use App\Reports\Application\Service\ReportRenderDataProjector;
use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\Definition\ApplicationBlock;
use App\Reports\Domain\Block\Definition\CommissionBlock;
use App\Reports\Domain\Block\Definition\ConclusionBlock;
use App\Reports\Domain\Block\Definition\ControlAreaBlock;
use App\Reports\Domain\Block\Definition\InstrumentsBlock;
use App\Reports\Domain\Block\Definition\NotesBlock;
use App\Reports\Domain\Block\Definition\PhotosBlock;
use App\Reports\Domain\Block\Definition\ProcessBlock;
use App\Reports\Domain\Block\Definition\RecommendationsBlock;
use App\Reports\Domain\Block\Definition\SurfacePrepBlock;
use App\Reports\Domain\Block\Definition\SystemBlock;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Infrastructure\Service\DocxTemplateRenderer;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Сквозной прогон на 3 блоках: агрегат отчёта + content → ReportRenderDataProjector → RenderData →
 * DocxTemplateRenderer → docx с подставленными значениями (шапка + скалярные поля блоков).
 */
final class ReportDocxRenderSmokeTest extends TestCase
{
    private string $templatePath;

    protected function setUp(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('АКТ № {{act_number}}');
        $section->addText('{{report_type}}');
        $section->addText('Класс ржавления: {{surface_prep_rustGrade}}');
        $section->addText('Выводы: {{conclusion_text}}');
        $section->addText('Примечания: {{notes_text}}');
        $section->addText('Слой 1: {{application_layer1_material}} — {{application_layer1_dry_film_mean}} мкм');

        $this->templatePath = sys_get_temp_dir().'/report_tpl_'.uniqid().'.docx';
        WordIO::createWriter($phpWord, 'Word2007')->save($this->templatePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
    }

    public function test_report_renders_to_docx_with_substitutions(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, '01-05-08-2026');
        $report->replaceContent([
            'surface_prep' => ['rustGrade' => 'B'],
            'conclusion' => ['text' => ['Соответствует регламенту.']],
            'notes' => ['text' => 'Без замечаний.'],
            'application' => ['layers' => [['material' => ['id' => 'c1', 'title' => 'Грунт ЭП-0199'], 'dry_film' => ['min' => 60, 'max' => 90, 'mean' => 80]]]],
        ], $now);

        $registry = new BlockRegistry([
            new SurfacePrepBlock(), new ConclusionBlock(), new NotesBlock(),
            new InstrumentsBlock(), new ProcessBlock(), new RecommendationsBlock(), new CommissionBlock(),
            new ApplicationBlock(), new PhotosBlock(), new ControlAreaBlock(), new SystemBlock(),
        ]);
        $projector = new ReportRenderDataProjector($registry, new ReportTemplateMap($registry), $this->createStub(FileStorage::class));
        $doc = (new DocxTemplateRenderer())->render(new TemplateFile($this->templatePath), $projector->project($report));

        self::assertSame(TemplateFormat::Docx, $doc->format);

        $text = $this->docxText($doc->content);
        self::assertStringContainsString('АКТ № 01-05-08-2026', $text);
        self::assertStringContainsString('Акт опытного нанесения', $text);
        self::assertStringContainsString('Класс ржавления: Степень B по ГОСТ Р ИСО 8501-1-2014', $text);
        self::assertStringContainsString('Выводы: 1. Соответствует регламенту.', $text);
        self::assertStringContainsString('Примечания: Без замечаний.', $text);
        self::assertStringContainsString('Слой 1: Грунт ЭП-0199 — 80 мкм', $text);
    }

    private function docxText(string $bytes): string
    {
        $path = sys_get_temp_dir().'/report_out_'.uniqid().'.docx';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $xml))));
    }
}
