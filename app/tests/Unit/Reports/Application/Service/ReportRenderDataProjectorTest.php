<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Application\Service;

use App\Reports\Application\Service\Mapping\ReportTemplateMap;
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
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReportRenderDataProjectorTest extends TestCase
{
    private ReportRenderDataProjector $projector;

    protected function setUp(): void
    {
        $registry = new BlockRegistry([
            new SurfacePrepBlock(), new ConclusionBlock(), new NotesBlock(),
            new InstrumentsBlock(), new ProcessBlock(), new RecommendationsBlock(), new CommissionBlock(),
            new ApplicationBlock(), new PhotosBlock(), new ControlAreaBlock(), new SystemBlock(),
        ]);
        $this->projector = new ReportRenderDataProjector($registry, new ReportTemplateMap($registry));
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
            'conclusion' => ['text' => ['Соответствует.', 'Допущено к эксплуатации.']],
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

        // блоки: {blockKey}_{fieldKey}. Enum-поля → полное documentText() (короткий код в форме, полное в акт)
        self::assertSame('Степень B по ГОСТ Р ИСО 8501-1-2014', $this->text($data, 'surface_prep_rustGrade'));
        self::assertSame('Абразивоструйная очистка до степени Sa 2½ по ISO 8501-1.', $this->text($data, 'surface_prep_prepDegree'));
        self::assertSame("1. Соответствует.\n2. Допущено к эксплуатации.", $this->text($data, 'conclusion_text'));
        self::assertSame('ок', $this->text($data, 'notes_text'));

        // список → текст (строки через перевод строки, под-поля через « — »)
        $commission = $this->text($data, 'commission_items');
        self::assertStringContainsString('ООО Литум — Н.С. Ванжин', $commission);
        self::assertStringContainsString('А.А. Баранов', $commission);

        // незаполненное поле — presence-driven, ключа нет
        self::assertFalse($data->has('surface_prep_abrasive'));
    }

    public function test_surface_prep_enum_fields_render_full_document_text(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-08');
        $report->replaceContent([
            'surface_prep' => ['dedusting' => '2', 'roughness' => 'Средний G'],
        ], $now);

        $data = $this->projector->project($report);

        self::assertSame('класс 2 по количеству и размеру частиц пыли согласно ISO 8502-3.', $this->text($data, 'surface_prep_dedusting'));
        self::assertSame('Средний G – между 2 и 3 сегментами, исключая сегмент 3, компаратора G по ISO 8503-2.', $this->text($data, 'surface_prep_roughness'));
    }

    public function test_unknown_enum_value_falls_back_to_raw_string(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-09');
        // старый отчёт: roughness хранит свободный текст, не совпадающий с кейсом enum → печатаем как есть
        $report->replaceContent(['surface_prep' => ['roughness' => 'Средний профиль G, старый ввод']], $now);

        self::assertSame('Средний профиль G, старый ввод', $this->text($this->projector->project($report), 'surface_prep_roughness'));
    }

    public function test_layers_project_to_indexed_keys(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-02');
        $report->replaceContent([
            'application' => ['layers' => [
                ['material' => ['id' => 'c1', 'title' => 'Грунт ЭП-0199'], 'dry_film' => ['min' => 88, 'max' => 297, 'mean' => 125], 'wet_film' => ['min' => 60, 'max' => 90], 'applied' => ['from' => '2026-07-30T15:00', 'to' => '2026-08-04T18:00']],
                ['material' => ['id' => 'c2', 'title' => 'Эмаль ХВ-785'], 'color' => 'RAL 7040'],
            ]],
        ], $now);

        $data = $this->projector->project($report);

        self::assertSame('2', $this->text($data, 'application_layer_count'));
        self::assertSame('Грунт ЭП-0199', $this->text($data, 'application_layer1_material'));
        // Толщина сухого слоя (объект) раскрывается в min/max/mean + range «мин–макс».
        self::assertSame('125', $this->text($data, 'application_layer1_dry_film_mean'));
        self::assertSame('88', $this->text($data, 'application_layer1_dry_film_min'));
        self::assertSame('297', $this->text($data, 'application_layer1_dry_film_max'));
        self::assertSame('88–297', $this->text($data, 'application_layer1_dry_film_range'));
        // Толщина мокрого слоя (диапазон без среднего) — min/max + range «мин–макс», без _mean.
        self::assertSame('60', $this->text($data, 'application_layer1_wet_film_min'));
        self::assertSame('90', $this->text($data, 'application_layer1_wet_film_max'));
        self::assertSame('60–90', $this->text($data, 'application_layer1_wet_film_range'));
        self::assertFalse($data->has('application_layer1_wet_film_mean'));
        // Период нанесения: дата — последняя (to), время — интервал «с–по».
        self::assertSame('04.08.2026', $this->text($data, 'application_layer1_date'));
        self::assertSame('15:00–18:00', $this->text($data, 'application_layer1_time'));
        self::assertSame('Эмаль ХВ-785', $this->text($data, 'application_layer2_material'));
        self::assertSame('RAL 7040', $this->text($data, 'application_layer2_color'));
        // незаполненные под-поля слоя — presence-driven, ключа нет
        self::assertFalse($data->has('application_layer1_color'));
        self::assertFalse($data->has('application_layer3_material'));
    }

    public function test_system_nominal_dft_total_projects_from_snapshot(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-06');
        // Тотал заморожен снимком при создании (CoatingSystem::totalDft) — проектор его лишь пробрасывает.
        $report->replaceContent([
            'system' => [
                'layers' => [
                    ['material' => ['id' => 'c1', 'title' => 'Грунт ЭП-0199'], 'dft_nominal' => 80],
                    ['material' => ['id' => 'c2', 'title' => 'Эмаль ХВ-785'], 'dft_nominal' => 120, 'color' => 'RAL 7040'],
                ],
                'dft_nominal_total' => 200,
            ],
        ], $now);

        $data = $this->projector->project($report);

        self::assertSame('200', $this->text($data, 'system_dft_nominal_total'));
        self::assertSame('80', $this->text($data, 'system_layer1_dft_nominal'));
        self::assertSame('120', $this->text($data, 'system_layer2_dft_nominal'));
    }

    public function test_old_report_without_seeded_total_has_no_key(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-07');
        // Старый отчёт (создан до засева тотала) — ключа в снимке нет → переменной тоже нет (в шаблоне {{...?}}).
        $report->replaceContent([
            'system' => ['layers' => [
                ['material' => ['id' => 'c1', 'title' => 'Грунт'], 'dft_nominal' => 80],
            ]],
        ], $now);

        self::assertFalse($this->projector->project($report)->has('system_dft_nominal_total'));
    }

    public function test_list_rows_project_to_repeat_group_and_flat(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-03');
        $report->replaceContent([
            'commission' => ['items' => [
                ['organization' => 'ЗМК Наста', 'position' => 'Инженер', 'name' => 'Иванов И.И.', 'date' => '2026-08-05'],
                ['organization' => 'Литум', 'name' => 'Петров П.П.'],
            ]],
        ], $now);

        $data = $this->projector->project($report);

        // Повторяемая группа под ключом блока.
        $group = $data->get('commission');
        self::assertInstanceOf(RepeatValue::class, $group);
        self::assertCount(2, $group->rows);
        self::assertSame('ЗМК Наста', $group->rows[0]['organization']);
        self::assertSame('Иванов И.И.', $group->rows[0]['name']);
        // Незаполненные подполя строки — пустая строка (драйвер очистит плейсхолдер).
        self::assertSame('', $group->rows[1]['position']);
        self::assertSame('Петров П.П.', $group->rows[1]['name']);
        // Плоский путь остаётся (обратная совместимость).
        self::assertTrue($data->has('commission_items'));
    }

    public function test_string_list_projects_repeat_group_and_numbered_flat(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-05');
        $report->replaceContent([
            'recommendations' => ['items' => ['Промыть пресной водой', '', 'Контроль ТСП через 24 ч']],
        ], $now);

        $data = $this->projector->project($report);

        // Повторяемая группа под ключом блока: непустые пункты, подключ text.
        $group = $data->get('recommendations');
        self::assertInstanceOf(RepeatValue::class, $group);
        self::assertSame([['text' => 'Промыть пресной водой'], ['text' => 'Контроль ТСП через 24 ч']], $group->rows);
        // Плоский нумерованный текст остаётся.
        self::assertSame("1. Промыть пресной водой\n2. Контроль ТСП через 24 ч", $this->text($data, 'recommendations_items'));
    }

    public function test_empty_list_rows_produce_no_repeat_group(): void
    {
        $now = new \DateTimeImmutable('2026-08-05');
        $report = new Report(Uuid::v7(), UuidService::generateUlid(), ReportType::TrialApplication, $now, $now, 'АКТ-04');
        $report->replaceContent(['commission' => ['items' => []]], $now);

        self::assertFalse($this->projector->project($report)->has('commission'));
    }

    private function text(RenderData $data, string $key): string
    {
        $value = $data->get($key);
        self::assertInstanceOf(TextValue::class, $value);

        return $value->value;
    }
}
