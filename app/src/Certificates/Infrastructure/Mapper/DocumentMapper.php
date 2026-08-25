<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Mapper;

use App\Certificates\Application\DTO\Documents\DocumentDTO;
use App\Certificates\Application\UseCase\Command\CreateDocument\CreateDocumentCommand;
use App\Certificates\Application\UseCase\Command\UpdateDocument\UpdateDocumentCommand;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Pure shape: форма ↔ команда/DTO. Бизнес-правила — в домене (Document), файл — валидация типа/размера
 * делается в контроллере/здесь как структурная. Пока UI привязывает документ только к системам —
 * все ссылки типа CoatingSystem; поддержка Coating есть в модели, в форму добавим позже.
 */
final class DocumentMapper
{
    private const MAX_FILE_BYTES = 15 * 1024 * 1024;

    /**
     * @param array<string, mixed> $input
     */
    public function buildCreateCommand(array $input, ?UploadedFile $file): CreateDocumentCommand
    {
        return new CreateDocumentCommand(
            $this->kind($input),
            $this->str($input, 'title'),
            $this->str($input, 'issuerId'),
            $this->date($input, 'issuedAt'),
            $this->nullableDate($input, 'expiresAt'),
            $this->str($input, 'subject'),
            $this->nullableStr($input, 'description'),
            $this->nullableStr($input, 'testStandard'),
            $this->references($input),
            $this->validatedFile($file),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function buildUpdateCommand(string $id, array $input, ?UploadedFile $file): UpdateDocumentCommand
    {
        return new UpdateDocumentCommand(
            $id,
            $this->kind($input),
            $this->str($input, 'title'),
            $this->str($input, 'issuerId'),
            $this->date($input, 'issuedAt'),
            $this->nullableDate($input, 'expiresAt'),
            $this->str($input, 'subject'),
            $this->nullableStr($input, 'description'),
            $this->nullableStr($input, 'testStandard'),
            $this->references($input),
            $this->validatedFile($file),
        );
    }

    /**
     * DTO → форма (префилл редактирования). referenceIds — только системы; заголовки систем
     * не резолвим (кросс-контекст), показываем как есть — typeahead отдаёт id, метку заменит выбор.
     *
     * @return array<string, mixed>
     */
    public function buildInputDataFromDto(DocumentDTO $dto): array
    {
        $systemIds = [];
        foreach ($dto->references as $reference) {
            if (ReferenceType::CoatingSystem->value === $reference->referenceType) {
                $systemIds[] = ['id' => $reference->referenceId];
            }
        }

        return [
            'id' => $dto->id,
            'kind' => $dto->kind,
            'title' => $dto->title,
            'issuerId' => $dto->issuerId,
            'issuedAt' => $dto->issuedAt->format('Y-m-d'),
            'expiresAt' => $dto->expiresAt?->format('Y-m-d') ?? '',
            'subject' => $dto->subject,
            'description' => $dto->description ?? '',
            'testStandard' => $dto->testStandard ?? '',
            'references' => $systemIds,
            'hasFile' => $dto->hasFile,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<Reference>
     */
    private function references(array $input): array
    {
        $rows = $input['references'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $references = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? (string) ($row['id'] ?? '') : (string) $row;
            $id = trim($id);
            if ('' === $id) {
                continue;
            }
            if (!Uuid::isValid($id)) {
                throw new AppException('Некорректная ссылка на систему.');
            }
            $references[] = new Reference(ReferenceType::CoatingSystem, Uuid::fromString($id));
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function kind(array $input): DocumentKind
    {
        $value = (string) ($input['kind'] ?? '');

        return DocumentKind::tryFrom($value) ?? throw new AppException('Некорректный вид документа.');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        return trim((string) ($input[$key] ?? ''));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableStr(array $input, string $key): ?string
    {
        $value = $this->str($input, $key);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function date(array $input, string $key): \DateTimeImmutable
    {
        $raw = $this->str($input, $key);
        if ('' === $raw) {
            throw new AppException('Дата выдачи обязательна.');
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new AppException('Некорректная дата.');
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableDate(array $input, string $key): ?\DateTimeImmutable
    {
        $raw = $this->str($input, $key);
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new AppException('Некорректная дата.');
        }
    }

    private function validatedFile(?UploadedFile $file): ?UploadedFile
    {
        if (null === $file) {
            return null;
        }
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            throw new AppException('Файл слишком большой (макс. 15 МБ).');
        }
        if ('application/pdf' !== $file->getMimeType()) {
            throw new AppException('Разрешён только PDF.');
        }

        return $file;
    }
}
