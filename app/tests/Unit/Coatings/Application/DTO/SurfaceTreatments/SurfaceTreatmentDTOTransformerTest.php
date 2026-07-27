<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\DTO\SurfaceTreatments;

use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTOTransformer;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SurfaceTreatmentDTOTransformerTest extends TestCase
{
    private SurfaceTreatmentDTOTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new SurfaceTreatmentDTOTransformer();
    }

    public function test_from_entity_happy_path(): void
    {
        $id = Uuid::v4();
        $treatment = new SurfaceTreatment(
            id: $id,
            description: 'При осмотре без применения увеличительных приборов...',
            code: 'Sa 2',
            standardCode: 'ГОСТ Р ИСО 8501-1',
            substrateScope: [Substrate::STEEL_CARBON, Substrate::ALUMINUM],
        );

        $dto = $this->transformer->fromEntity($treatment);

        $this->assertSame((string) $id, $dto->id);
        $this->assertSame('При осмотре без применения увеличительных приборов...', $dto->description);
        $this->assertSame('Sa 2', $dto->code);
        $this->assertSame('ГОСТ Р ИСО 8501-1', $dto->standardCode);
        $this->assertSame(['steel_carbon', 'aluminum'], $dto->substrateScope);
        $this->assertSame(['Углеродистая сталь', 'Алюминий'], $dto->substrateScopeTitles);
        $this->assertSame('Sa 2 (ГОСТ Р ИСО 8501-1)', $dto->title);
        $this->assertNotNull($dto->createdAt);
        $this->assertNotNull($dto->updatedAt);
    }

    public function test_from_entity_description_only(): void
    {
        $id = Uuid::v4();
        $treatment = new SurfaceTreatment(
            id: $id,
            description: 'Обмыв водой',
            code: null,
            standardCode: null,
            substrateScope: [Substrate::CONCRETE],
        );

        $dto = $this->transformer->fromEntity($treatment);

        $this->assertSame('Обмыв водой', $dto->title);
    }

    public function test_from_entity_code_and_standard_code(): void
    {
        $id = Uuid::v4();
        $treatment = new SurfaceTreatment(
            id: $id,
            description: 'Some description',
            code: 'Sa 3',
            standardCode: 'ISO 8501-1',
            substrateScope: [Substrate::STEEL_GALVANIZED],
        );

        $dto = $this->transformer->fromEntity($treatment);

        $this->assertSame('Sa 3 (ISO 8501-1)', $dto->title);
    }

    public function test_from_entity_code_without_standard_code(): void
    {
        $id = Uuid::v4();
        $treatment = new SurfaceTreatment(
            id: $id,
            description: 'Some description',
            code: 'Sa 2.5',
            standardCode: null,
            substrateScope: [Substrate::STEEL_METALLIZED],
        );

        $dto = $this->transformer->fromEntity($treatment);

        $this->assertSame('Sa 2.5', $dto->title);
    }

    public function test_from_entity_multiple_substrates(): void
    {
        $id = Uuid::v4();
        $treatment = new SurfaceTreatment(
            id: $id,
            description: 'Test',
            code: 'Test',
            standardCode: null,
            substrateScope: [Substrate::STEEL_CARBON, Substrate::STEEL_GALVANIZED, Substrate::CONCRETE],
        );

        $dto = $this->transformer->fromEntity($treatment);

        $this->assertSame(
            ['steel_carbon', 'steel_galvanized', 'concrete'],
            $dto->substrateScope,
        );
        $this->assertSame(
            ['Углеродистая сталь', 'Оцинкованная сталь', 'Бетон'],
            $dto->substrateScopeTitles,
        );
    }
}
