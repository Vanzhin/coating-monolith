<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTO;
use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\UpdateSurfaceTreatment\UpdateSurfaceTreatmentCommand;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Mapper\SurfaceTreatmentMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SurfaceTreatmentMapperTest extends TestCase
{
    private SurfaceTreatmentMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new SurfaceTreatmentMapper();
    }

    public function test_build_command_from_input_data_create_command(): void
    {
        $input = [
            'description' => 'Test treatment',
            'code' => 'Sa 2',
            'standardCode' => 'ISO 8501-1',
            'substrateScope' => ['steel_carbon', 'aluminum'],
        ];

        $command = $this->mapper->buildCommandFromInputData($input);

        $this->assertInstanceOf(CreateSurfaceTreatmentCommand::class, $command);
        $this->assertSame('Test treatment', $command->description);
        $this->assertSame('Sa 2', $command->code);
        $this->assertSame('ISO 8501-1', $command->standardCode);
        $this->assertCount(2, $command->substrateScope);
        $this->assertSame(Substrate::STEEL_CARBON, $command->substrateScope[0]);
        $this->assertSame(Substrate::ALUMINUM, $command->substrateScope[1]);
    }

    public function test_build_command_from_input_data_update_command(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $input = [
            'description' => 'Updated treatment',
            'code' => 'Sa 3',
            'standardCode' => null,
            'substrateScope' => ['concrete'],
        ];

        $command = $this->mapper->buildCommandFromInputData($input, $id);

        $this->assertInstanceOf(UpdateSurfaceTreatmentCommand::class, $command);
        $this->assertSame($id, $command->id);
        $this->assertSame('Updated treatment', $command->description);
        $this->assertSame('Sa 3', $command->code);
        $this->assertNull($command->standardCode);
        $this->assertCount(1, $command->substrateScope);
        $this->assertSame(Substrate::CONCRETE, $command->substrateScope[0]);
    }

    public function test_build_command_empty_code_becomes_null(): void
    {
        $input = [
            'description' => 'Test',
            'code' => '',
            'standardCode' => 'ISO 8501-1',
            'substrateScope' => ['steel_carbon'],
        ];

        $command = $this->mapper->buildCommandFromInputData($input);

        $this->assertNull($command->code);
        $this->assertSame('ISO 8501-1', $command->standardCode);
    }

    public function test_build_command_empty_standard_code_becomes_null(): void
    {
        $input = [
            'description' => 'Test',
            'code' => 'Sa 2',
            'standardCode' => '  ',
            'substrateScope' => ['steel_carbon'],
        ];

        $command = $this->mapper->buildCommandFromInputData($input);

        $this->assertSame('Sa 2', $command->code);
        $this->assertNull($command->standardCode);
    }

    public function test_build_command_empty_code_and_standard_code_both_null(): void
    {
        $input = [
            'description' => 'Обмыв водой',
            'code' => '',
            'standardCode' => null,
            'substrateScope' => ['concrete'],
        ];

        $command = $this->mapper->buildCommandFromInputData($input);

        $this->assertNull($command->code);
        $this->assertNull($command->standardCode);
    }

    public function test_build_input_data_from_dto_round_trip(): void
    {
        $dto = new SurfaceTreatmentDTO();
        $dto->id = Uuid::v4()->toRfc4122();
        $dto->description = 'Test treatment';
        $dto->code = 'Sa 2';
        $dto->standardCode = 'ISO 8501-1';
        $dto->substrateScope = ['steel_carbon', 'aluminum'];
        $dto->substrateScopeTitles = ['Углеродистая сталь', 'Алюминий'];
        $dto->title = 'Sa 2 (ISO 8501-1)';
        $dto->createdAt = new \DateTimeImmutable();
        $dto->updatedAt = new \DateTimeImmutable();

        $data = $this->mapper->buildInputDataFromDto($dto);

        $this->assertSame('Test treatment', $data['description']);
        $this->assertSame('Sa 2', $data['code']);
        $this->assertSame('ISO 8501-1', $data['standardCode']);
        $this->assertSame(['steel_carbon', 'aluminum'], $data['substrateScope']);
    }

    public function test_build_input_data_from_dto_with_null_fields(): void
    {
        $dto = new SurfaceTreatmentDTO();
        $dto->id = Uuid::v4()->toRfc4122();
        $dto->description = 'Simple description';
        $dto->code = null;
        $dto->standardCode = null;
        $dto->substrateScope = ['concrete'];
        $dto->substrateScopeTitles = ['Бетон'];
        $dto->title = 'Simple description';
        $dto->createdAt = new \DateTimeImmutable();
        $dto->updatedAt = new \DateTimeImmutable();

        $data = $this->mapper->buildInputDataFromDto($dto);

        $this->assertSame('Simple description', $data['description']);
        $this->assertSame('', $data['code']);
        $this->assertSame('', $data['standardCode']);
        $this->assertSame(['concrete'], $data['substrateScope']);
    }

    public function test_build_input_data_from_dto_null(): void
    {
        $data = $this->mapper->buildInputDataFromDto(null);

        $this->assertSame('', $data['description']);
        $this->assertSame('', $data['code']);
        $this->assertSame('', $data['standardCode']);
        $this->assertSame([], $data['substrateScope']);
    }

    public function test_get_validation_collection_constraints(): void
    {
        $collection = $this->mapper->getValidationCollection();

        $this->assertNotNull($collection);
        $this->assertTrue($collection->allowExtraFields);
    }
}
