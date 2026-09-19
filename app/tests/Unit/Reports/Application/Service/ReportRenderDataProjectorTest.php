<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Application\Service;

use App\Reports\Application\Service\ReportRenderDataProjector;
use App\Reports\Domain\Aggregate\Report\Reference;
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
use App\Shared\Domain\Service\UuidService;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TextValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReportRenderDataProjectorTest extends TestCase
{
    private ReportRenderDataProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new ReportRenderDataProjector(
            new BlockRegistry([
                new SurfacePrepBlock(), new ConclusionBlock(), new NotesBlock(),
                new InstrumentsBlock(), new ProcessBlock(), new RecommendationsBlock(), new CommissionBlock(),
                new ApplicationBlock(), new PhotosBlock(), new ControlAreaBlock(), new SystemBlock(),
            ]),
        );
    }

    public function test_projects_header_and_scalar_blocks(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-01');
        $report->applyReferences(
            new Reference('p1', 'Усольский ГОК'),
            new Reference('c1', 'ЕвроХим'),
            new Reference('c2', 'НПП НГТ'),
            null,
            $now,
        );
        $report->replaceContent([
            'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
            'conclusion' => ['text' => 'Соответствует.'],
            'notes' => ['text' => 'ок'],
            'commission' => ['items' => [
                ['organization' => 'ООО Литум', 'name' => 'Н.С. Ванжин'],
                ['name' => 'А.А. Баранов'],
            ]],
        ], $now);

        $data = $this->projector->project($report);

        // шапка
        self::assertSame('АКТ-01', $this->text($data, 'act_number'));
        self::assertSame('05.08.2026', $this->text($data, 'report_date'));
        self::assertSame('Акт опытного нанесения', $this->text($data, 'report_type'));
        self::assertSame('Создан', $this->text($data, 'status'));
        self::assertSame('Усольский ГОК', $this->text($data, 'project_title'));
        self::assertSame('ЕвроХим', $this->text($data, 'customer_title'));
        self::assertSame('НПП НГТ', $this->text($data, 'contractor_title'));
        self::assertFalse($data->has('system_title')); // null-ссылка → ключа нет

        // блоки: {blockKey}_{fieldKey}
        self::assertSame('B', $this->text($data, 'surface_prep_rustGrade'));
        self::assertSame('Sa 2½', $this->text($data, 'surface_prep_prepDegree'));
        self::assertSame('Соответствует.', $this->text($data, 'conclusion_text'));
        self::assertSame('ок', $this->text($data, 'notes_text'));

        // список → текст (строки через перевод строки, под-поля через « — »)
        $commission = $this->text($data, 'commission_items');
        self::assertStringContainsString('ООО Литум — Н.С. Ванжин', $commission);
        self::assertStringContainsString('А.А. Баранов', $commission);

        // незаполненное поле — presence-driven, ключа нет
        self::assertFalse($data->has('surface_prep_abrasive'));
    }

    public function test_layers_project_to_indexed_keys(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-02');
        $report->replaceContent([
            'application' => ['layers' => [
                ['material' => ['id' => 'c1', 'title' => 'Грунт ЭП-0199'], 'dry_film_mean' => 80],
                ['material' => ['id' => 'c2', 'title' => 'Эмаль ХВ-785'], 'dry_film_mean' => 60, 'color' => 'RAL 7040'],
            ]],
        ], $now);

        $data = $this->projector->project($report);

        self::assertSame('2', $this->text($data, 'application_layer_count'));
        self::assertSame('Грунт ЭП-0199', $this->text($data, 'application_layer1_material'));
        self::assertSame('80', $this->text($data, 'application_layer1_dry_film_mean'));
        self::assertSame('Эмаль ХВ-785', $this->text($data, 'application_layer2_material'));
        self::assertSame('RAL 7040', $this->text($data, 'application_layer2_color'));
        // незаполненные под-поля слоя — presence-driven, ключа нет
        self::assertFalse($data->has('application_layer1_color'));
        self::assertFalse($data->has('application_layer3_material'));
    }

    private function text(RenderData $data, string $key): string
    {
        $value = $data->get($key);
        self::assertInstanceOf(TextValue::class, $value);

        return $value->value;
    }
}
