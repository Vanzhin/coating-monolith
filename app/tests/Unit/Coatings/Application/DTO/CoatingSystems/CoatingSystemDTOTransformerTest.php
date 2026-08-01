<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemLayerDTO;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemDTOTransformerTest extends TestCase
{
    public function test_from_entity_populates_all_fields(): void
    {
        $coating1 = $this->makeCoating('Coating 1', CoatingBase::EP, 80, 150);
        $coating2 = $this->makeCoating('Coating 2', CoatingBase::PUR, 50, 120);

        $treatment = $this->newTreatment('Sa 2.5', 'Blast clean', 'ISO 8501-1');
        $system = new CoatingSystem(
            UuidService::generateUuid(),
            'Test System',
            'System description',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating1, 100);
        $system->appendLayer($coating2, 80);

        $transformer = new CoatingSystemDTOTransformer();
        $dto = $transformer->fromEntity($system);

        $this->assertInstanceOf(CoatingSystemDTO::class, $dto);
        $this->assertSame($system->getId(), $dto->id);
        $this->assertSame('Test System', $dto->title);
        $this->assertSame('System description', $dto->description);
        $this->assertSame('steel_carbon', $dto->substrate);
        $this->assertSame('Углеродистая сталь', $dto->substrateTitle);
        $this->assertSame($treatment->getId(), $dto->surfaceTreatmentId);
        $this->assertSame('Blast clean', $dto->surfaceTreatmentDescription);
        $this->assertSame('Sa 2.5', $dto->surfaceTreatmentCode);
        $this->assertSame('ISO 8501-1', $dto->surfaceTreatmentStandardCode);
        $this->assertSame('Sa 2.5', $dto->surfaceTreatmentTitle);
        $this->assertSame(180, $dto->totalDft);
        $this->assertEquals($system->getCreatedAt(), $dto->createdAt);
        $this->assertEquals($system->getUpdatedAt(), $dto->updatedAt);
        $this->assertCount(2, $dto->layers);
    }

    public function test_from_entity_layers_contain_correct_data(): void
    {
        $coating = $this->makeCoating('EP White', CoatingBase::EP, 80, 150);
        $coating->setIsZincRich(true);

        $treatment = $this->newTreatment('Sa 2', 'Surface prep', null);
        $system = new CoatingSystem(
            UuidService::generateUuid(),
            'System',
            'desc',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $layer = $system->appendLayer($coating, 120);

        $dto = (new CoatingSystemDTOTransformer())->fromEntity($system);

        $this->assertCount(1, $dto->layers);
        $layerDto = $dto->layers[0];
        $this->assertInstanceOf(CoatingSystemLayerDTO::class, $layerDto);
        $this->assertSame($layer->getId(), $layerDto->id);
        $this->assertSame(1, $layerDto->position);
        $this->assertSame(120, $layerDto->dft);
        $this->assertSame($coating->getId(), $layerDto->coatingId);
        $this->assertSame('EP White', $layerDto->coatingTitle);
        $this->assertSame('EP', $layerDto->coatingBase);
        $this->assertSame('Эпоксидное', $layerDto->coatingBaseTitle);
        $this->assertTrue($layerDto->isZincRich);
    }

    public function test_from_entity_treatment_without_code_uses_description_as_title(): void
    {
        $treatment = $this->newTreatment(null, 'Обмыв водой', null);
        $system = new CoatingSystem(
            UuidService::generateUuid(),
            'System',
            'desc',
            Substrate::ALUMINUM,
            $treatment,
        );

        $dto = (new CoatingSystemDTOTransformer())->fromEntity($system);

        $this->assertNull($dto->surfaceTreatmentCode);
        $this->assertNull($dto->surfaceTreatmentStandardCode);
        $this->assertSame('Обмыв водой', $dto->surfaceTreatmentTitle);
    }

    public function test_from_entity_compliance_empty_by_default(): void
    {
        $treatment = $this->newTreatment('Grade', 'Preparation', null);
        $system = new CoatingSystem(
            UuidService::generateUuid(),
            'System',
            'desc',
            Substrate::CONCRETE,
            $treatment,
        );

        $dto = (new CoatingSystemDTOTransformer())->fromEntity($system);

        $this->assertSame([], $dto->compliance);
    }

    private function newTreatment(?string $code, string $description, ?string $standardCode): SurfaceTreatment
    {
        return new SurfaceTreatment(
            Uuid::v7(),
            $description,
            $code,
            $standardCode,
            Substrate::cases(),
        );
    }

    private function makeCoating(
        string $title,
        CoatingBase $base,
        int $dftMin,
        int $dftMax,
    ): Coating {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');
        $manufacturer->method('getTitle')->willReturn('Mfg');
        $manufacturer->method('getDescription')->willReturn('');

        $spec = new CoatingSpecification(
            $this->createMock(UniqueTitleCoatingSpecification::class),
        );

        return new Coating(
            UuidService::generateUuid(),
            $title,
            'desc',
            50,
            1.2,
            $base,
            new DftRange(new PositiveNumberRange($dftMin, $dftMax), (int) (($dftMin + $dftMax) / 2), ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            null,
            1.0,
            null,
            $manufacturer,
            $spec,
            50,
        );
    }
}
