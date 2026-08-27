<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Infrastructure\Mapper;

use App\Certificates\Application\DTO\Documents\DocumentDTO;
use App\Certificates\Application\DTO\Documents\DocumentReferenceDTO;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Infrastructure\Mapper\DocumentMapper;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class DocumentMapperTest extends TestCase
{
    private const SYSTEM_ID = '01a03a3a-7673-7d52-b977-64be42e68bcc';
    private const COATING_ID = '018f0000-0000-7000-8000-000000000abc';

    public function test_references_map_both_types_from_form(): void
    {
        $command = (new DocumentMapper())->buildCreateCommand($this->baseInput([
            ['type' => ReferenceType::CoatingSystem->value, 'id' => self::SYSTEM_ID],
            ['type' => ReferenceType::Coating->value, 'id' => self::COATING_ID],
        ]), null);

        self::assertCount(2, $command->references);
        self::assertSame(ReferenceType::CoatingSystem, $command->references[0]->referenceType);
        self::assertSame(self::SYSTEM_ID, (string) $command->references[0]->referenceId);
        self::assertSame(ReferenceType::Coating, $command->references[1]->referenceType);
        self::assertSame(self::COATING_ID, (string) $command->references[1]->referenceId);
    }

    public function test_build_input_data_from_dto_round_trips_references(): void
    {
        $mapper = new DocumentMapper();
        $dto = $this->dtoWithReferences([
            [ReferenceType::CoatingSystem->value, self::SYSTEM_ID],
            [ReferenceType::Coating->value, self::COATING_ID],
        ]);

        $input = $mapper->buildInputDataFromDto($dto);

        self::assertSame(
            [
                ['type' => ReferenceType::CoatingSystem->value, 'id' => self::SYSTEM_ID],
                ['type' => ReferenceType::Coating->value, 'id' => self::COATING_ID],
            ],
            $input['references'],
        );

        // Форма → команда должна дать те же ссылки (round-trip).
        $command = $mapper->buildCreateCommand($this->baseInput($input['references']), null);
        self::assertSame(ReferenceType::CoatingSystem, $command->references[0]->referenceType);
        self::assertSame(self::SYSTEM_ID, (string) $command->references[0]->referenceId);
        self::assertSame(ReferenceType::Coating, $command->references[1]->referenceType);
        self::assertSame(self::COATING_ID, (string) $command->references[1]->referenceId);
    }

    public function test_empty_reference_id_is_skipped(): void
    {
        $command = (new DocumentMapper())->buildCreateCommand($this->baseInput([
            ['type' => ReferenceType::CoatingSystem->value, 'id' => ''],
            ['type' => ReferenceType::Coating->value, 'id' => self::COATING_ID],
        ]), null);

        self::assertCount(1, $command->references);
        self::assertSame(self::COATING_ID, (string) $command->references[0]->referenceId);
    }

    public function test_invalid_reference_uuid_throws(): void
    {
        $this->expectException(AppException::class);

        (new DocumentMapper())->buildCreateCommand($this->baseInput([
            ['type' => ReferenceType::Coating->value, 'id' => 'not-a-uuid'],
        ]), null);
    }

    public function test_invalid_reference_type_throws(): void
    {
        $this->expectException(AppException::class);

        (new DocumentMapper())->buildCreateCommand($this->baseInput([
            ['type' => 'bogus_type', 'id' => self::SYSTEM_ID],
        ]), null);
    }

    /**
     * @param list<array{type: string, id: string}> $references
     *
     * @return array<string, mixed>
     */
    private function baseInput(array $references): array
    {
        return [
            'kind' => DocumentKind::cases()[0]->value,
            'title' => '55-2023/ЦС',
            'issuerId' => self::SYSTEM_ID,
            'issuedAt' => '2024-01-15',
            'subject' => 'C5, 15-25 лет',
            'references' => $references,
        ];
    }

    /**
     * @param list<array{0: string, 1: string}> $references
     */
    private function dtoWithReferences(array $references): DocumentDTO
    {
        $dto = new DocumentDTO();
        $dto->id = self::SYSTEM_ID;
        $dto->kind = DocumentKind::cases()[0]->value;
        $dto->kindLabel = DocumentKind::cases()[0]->label();
        $dto->title = '55-2023/ЦС';
        $dto->issuerId = self::SYSTEM_ID;
        $dto->issuedAt = new \DateTimeImmutable('2024-01-15');
        $dto->subject = 'C5, 15-25 лет';
        $dto->references = array_map(
            static function (array $pair): DocumentReferenceDTO {
                $ref = new DocumentReferenceDTO();
                $ref->referenceType = $pair[0];
                $ref->referenceTypeLabel = ReferenceType::from($pair[0])->label();
                $ref->referenceId = $pair[1];

                return $ref;
            },
            $references,
        );

        return $dto;
    }
}
