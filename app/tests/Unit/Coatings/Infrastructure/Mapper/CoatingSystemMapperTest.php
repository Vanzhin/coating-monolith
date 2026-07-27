<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemLayerDTO;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommand;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Mapper\CoatingSystemMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;

final class CoatingSystemMapperTest extends TestCase
{
    private CoatingSystemMapper $mapper;

    private const TREATMENT_UUID = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        $this->mapper = new CoatingSystemMapper();
    }

    public function test_build_create_command_from_input(): void
    {
        $input = $this->validInput();

        $cmd = $this->mapper->buildCommandFromInputData($input);

        self::assertInstanceOf(CreateCoatingSystemCommand::class, $cmd);
        self::assertSame('Test System', $cmd->title);
        self::assertSame('Some description', $cmd->description);
        self::assertSame(Substrate::STEEL_CARBON, $cmd->substrate);
        self::assertSame(self::TREATMENT_UUID, $cmd->surfaceTreatmentId);
        self::assertCount(2, $cmd->initialLayers);
        self::assertSame('uuid-1', $cmd->initialLayers[0]['coatingId']);
        self::assertSame(60, $cmd->initialLayers[0]['dft']);
        self::assertSame('uuid-2', $cmd->initialLayers[1]['coatingId']);
        self::assertSame(100, $cmd->initialLayers[1]['dft']);
    }

    public function test_build_update_command_from_input_when_system_id_given(): void
    {
        $input = $this->validInput();

        $cmd = $this->mapper->buildCommandFromInputData($input, 'system-uuid-1');

        self::assertInstanceOf(UpdateCoatingSystemMetadataCommand::class, $cmd);
        self::assertSame('system-uuid-1', $cmd->id);
        self::assertSame('Test System', $cmd->title);
        self::assertSame(Substrate::STEEL_CARBON, $cmd->substrate);
        self::assertSame(self::TREATMENT_UUID, $cmd->surfaceTreatmentId);
    }

    public function test_round_trip(): void
    {
        $input = [
            'title' => 'Test',
            'description' => 'desc',
            'substrate' => 'steel_carbon',
            'surfaceTreatmentId' => self::TREATMENT_UUID,
            'surfaceTreatmentTitle' => 'Sa 2½',
            'layers' => [
                ['coatingId' => 'uuid-1', 'dft' => 60],
                ['coatingId' => 'uuid-2', 'dft' => 100],
            ],
        ];

        $dto = $this->buildDtoFromInput($input);

        self::assertSame($input, $this->mapper->buildInputDataFromDto($dto));
    }

    public function test_round_trip_without_treatment_title(): void
    {
        $input = [
            'title' => 'Test',
            'description' => '',
            'substrate' => 'concrete',
            'surfaceTreatmentId' => self::TREATMENT_UUID,
            'surfaceTreatmentTitle' => 'Обмыв водой',
            'layers' => [],
        ];

        $dto = $this->buildDtoFromInput($input);

        self::assertSame($input, $this->mapper->buildInputDataFromDto($dto));
    }

    public function test_build_input_from_null_dto_returns_empty_structure(): void
    {
        $result = $this->mapper->buildInputDataFromDto(null);

        self::assertSame('', $result['title']);
        self::assertSame('', $result['description']);
        self::assertSame('', $result['substrate']);
        self::assertSame('', $result['surfaceTreatmentId']);
        self::assertSame('', $result['surfaceTreatmentTitle']);
        self::assertSame([], $result['layers']);
    }

    public function test_validation_collection_is_collection_constraint(): void
    {
        $collection = $this->mapper->getValidationCollection();

        self::assertInstanceOf(Assert\Collection::class, $collection);
    }

    public function test_validation_collection_has_required_fields(): void
    {
        $collection = $this->mapper->getValidationCollection();
        $fields = $collection->fields;

        self::assertArrayHasKey('title', $fields);
        self::assertArrayHasKey('description', $fields);
        self::assertArrayHasKey('substrate', $fields);
        self::assertArrayHasKey('surfaceTreatmentId', $fields);
        self::assertArrayHasKey('layers', $fields);
    }

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        return [
            'title' => 'Test System',
            'description' => 'Some description',
            'substrate' => 'steel_carbon',
            'surfaceTreatmentId' => self::TREATMENT_UUID,
            'layers' => [
                ['coatingId' => 'uuid-1', 'dft' => 60],
                ['coatingId' => 'uuid-2', 'dft' => 100],
            ],
        ];
    }

    /**
     * Constructs a CoatingSystemDTO from flat input data (mirrors what the mapper produces).
     */
    /** @param array<string, mixed> $input */
    private function buildDtoFromInput(array $input): CoatingSystemDTO
    {
        $dto = new CoatingSystemDTO();
        $dto->id = 'irrelevant-id';
        $dto->title = $input['title'];
        $dto->description = $input['description'];
        $dto->substrate = $input['substrate'];
        $dto->substrateTitle = Substrate::from($input['substrate'])->title();
        $dto->surfaceTreatmentId = $input['surfaceTreatmentId'];
        $dto->surfaceTreatmentDescription = 'some description';
        $dto->surfaceTreatmentTitle = $input['surfaceTreatmentTitle'];
        $dto->totalDft = 0;
        $dto->createdAt = new \DateTimeImmutable();
        $dto->updatedAt = new \DateTimeImmutable();

        foreach ($input['layers'] as $layer) {
            $layerDto = new CoatingSystemLayerDTO();
            $layerDto->id = 'layer-id';
            $layerDto->position = 0;
            $layerDto->coatingId = $layer['coatingId'];
            $layerDto->dft = $layer['dft'];
            $layerDto->coatingTitle = '';
            $layerDto->coatingBase = '';
            $layerDto->coatingBaseTitle = '';
            $layerDto->isZincRich = false;
            $dto->layers[] = $layerDto;
        }

        return $dto;
    }
}
