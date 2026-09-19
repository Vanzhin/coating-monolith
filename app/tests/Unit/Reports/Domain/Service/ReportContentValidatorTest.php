<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Domain\Service;

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
use App\Reports\Domain\Service\ReportContentValidator;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ReportContentValidatorTest extends TestCase
{
    private ReportContentValidator $validator;

    protected function setUp(): void
    {
        $registry = new BlockRegistry([
            new SurfacePrepBlock(), new ConclusionBlock(), new NotesBlock(),
            new InstrumentsBlock(), new ProcessBlock(), new RecommendationsBlock(), new CommissionBlock(),
            new ApplicationBlock(), new PhotosBlock(), new ControlAreaBlock(),
        ]);
        $this->validator = new ReportContentValidator($registry);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function validContent(): array
    {
        return [
            'control_area' => ['description' => 'Балка Б-1, нижняя полка', 'area' => 2.5],
            'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½', 'dedusting' => '2'],
            'conclusion' => ['text' => 'Соответствует регламенту.'],
            'notes' => ['text' => 'Без замечаний.'],
        ];
    }

    public function test_valid_content_passes_strict(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(ReportType::TrialApplication, $this->validContent(), strict: true);
    }

    public function test_control_area_description_required_strict(): void
    {
        $content = $this->validContent();
        unset($content['control_area']); // участок не описан

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: true);
    }

    public function test_light_mode_allows_incomplete(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(ReportType::TrialApplication, [], strict: false);
    }

    public function test_strict_missing_required_throws(): void
    {
        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, [], strict: true);
    }

    public function test_unknown_enum_value_throws_even_in_light_mode(): void
    {
        $content = $this->validContent();
        $content['surface_prep']['rustGrade'] = 'Z';

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: false);
    }

    public function test_reference_area_uses_its_own_composition(): void
    {
        // ReferenceArea не содержит Conclusion → его обязательность не требуется даже в strict.
        $this->expectNotToPerformAssertions();
        $this->validator->validate(ReportType::ReferenceArea, [
            'control_area' => ['description' => 'Балка Б-1'],
            'surface_prep' => ['rustGrade' => 'A', 'prepDegree' => 'Sa 3'],
        ], strict: true);
    }

    public function test_list_row_missing_required_sub_field_throws_strict(): void
    {
        $content = $this->validContent();
        $content['commission'] = ['items' => [['organization' => 'ООО Литум']]]; // строка без ФИО (required)

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: true);
    }

    public function test_valid_list_passes_strict(): void
    {
        $this->expectNotToPerformAssertions();
        $content = $this->validContent();
        $content['commission'] = ['items' => [['name' => 'Н.С. Ванжин', 'organization' => 'ООО Литум']]];
        $content['instruments'] = ['items' => [['name' => 'Позитектор', 'serial' => '798019']]];
        $this->validator->validate(ReportType::TrialApplication, $content, strict: true);
    }

    public function test_non_list_value_throws(): void
    {
        $content = $this->validContent();
        $content['instruments'] = ['items' => 'не список'];

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: false);
    }

    public function test_valid_layers_pass_strict(): void
    {
        $this->expectNotToPerformAssertions();
        $content = $this->validContent();
        $content['application'] = ['layers' => [
            ['material' => ['id' => 'c1', 'title' => 'Грунт ЭП-0199'], 'dry_film_mean' => 80, 'surface_temp' => 12],
            ['material' => ['id' => 'c2', 'title' => 'Эмаль ХВ-785'], 'dry_film_mean' => 60],
        ]];
        $this->validator->validate(ReportType::TrialApplication, $content, strict: true);
    }

    public function test_layer_missing_required_material_throws_strict(): void
    {
        $content = $this->validContent();
        $content['application'] = ['layers' => [['dry_film_mean' => 80]]]; // слой без материала (required)

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: true);
    }

    public function test_layer_material_must_be_ref_shape(): void
    {
        $content = $this->validContent();
        $content['application'] = ['layers' => [['material' => 'Грунт ЭП-0199']]]; // строка вместо ссылки {id,title}

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: false);
    }

    public function test_layers_over_limit_throws(): void
    {
        $content = $this->validContent();
        $content['application'] = ['layers' => array_fill(0, 5, ['material' => ['id' => 'c1', 'title' => 'Слой']])]; // 5 > 4

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: false);
    }

    public function test_valid_photos_pass_strict(): void
    {
        $this->expectNotToPerformAssertions();
        $content = $this->validContent();
        $content['photos'] = ['items' => [
            ['file' => 'uuid-1', 'caption' => 'Общий вид'],
            ['file' => 'uuid-2'], // подпись необязательна
        ]];
        $this->validator->validate(ReportType::TrialApplication, $content, strict: true);
    }

    public function test_photo_without_file_throws_strict(): void
    {
        $content = $this->validContent();
        $content['photos'] = ['items' => [['caption' => 'Без файла']]]; // file (uuid) обязателен в строгом режиме

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: true);
    }

    public function test_photos_over_limit_throws(): void
    {
        $content = $this->validContent();
        $content['photos'] = ['items' => array_map(
            static fn (int $i): array => ['file' => 'uuid-'.$i],
            range(1, 21), // 21 > 20
        )];

        $this->expectException(AppException::class);
        $this->validator->validate(ReportType::TrialApplication, $content, strict: false);
    }
}
