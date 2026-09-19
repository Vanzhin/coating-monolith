<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Domain\Service;

use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\Definition\ConclusionBlock;
use App\Reports\Domain\Block\Definition\NotesBlock;
use App\Reports\Domain\Block\Definition\SurfacePrepBlock;
use App\Reports\Domain\Service\ReportContentValidator;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ReportContentValidatorTest extends TestCase
{
    private ReportContentValidator $validator;

    protected function setUp(): void
    {
        $registry = new BlockRegistry([new SurfacePrepBlock(), new ConclusionBlock(), new NotesBlock()]);
        $this->validator = new ReportContentValidator($registry);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function validContent(): array
    {
        return [
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
        // ReferenceArea = [SurfacePrep, Notes]; Conclusion не входит → его обязательность не требуется.
        $this->expectNotToPerformAssertions();
        $this->validator->validate(ReportType::ReferenceArea, [
            'surface_prep' => ['rustGrade' => 'A', 'prepDegree' => 'Sa 3'],
        ], strict: true);
    }
}
