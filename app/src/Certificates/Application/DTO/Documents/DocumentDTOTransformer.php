<?php

declare(strict_types=1);

namespace App\Certificates\Application\DTO\Documents;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\Reference;

class DocumentDTOTransformer
{
    public function fromEntity(Document $document, ?string $issuerTitle = null): DocumentDTO
    {
        $dto = new DocumentDTO();
        $dto->id = $document->getId();
        $dto->kind = $document->getKind()->value;
        $dto->kindLabel = $document->getKind()->label();
        $dto->title = $document->getTitle();
        $dto->issuerId = $document->getIssuerId();
        $dto->issuerTitle = $issuerTitle;
        $dto->issuedAt = $document->getIssuedAt();
        $dto->expiresAt = $document->getExpiresAt();
        $dto->testStandard = $document->getTestStandard();
        $dto->subject = $document->getSubject();
        $dto->description = $document->getDescription();
        $dto->file = $document->getFile();
        $dto->hasFile = null !== $document->getFile();
        $dto->isExpired = $document->isExpired();
        $dto->references = array_map(
            fn (Reference $reference) => $this->referenceDto($reference),
            $document->references(),
        );
        $dto->createdAt = $document->getCreatedAt();
        $dto->updatedAt = $document->getUpdatedAt();

        return $dto;
    }

    /**
     * @param iterable<Document>    $documents
     * @param array<string, string> $issuerTitles map issuerId → title
     *
     * @return list<DocumentDTO>
     */
    public function fromEntityList(iterable $documents, array $issuerTitles = []): array
    {
        $dtos = [];
        foreach ($documents as $document) {
            $dtos[] = $this->fromEntity($document, $issuerTitles[$document->getIssuerId()] ?? null);
        }

        return $dtos;
    }

    private function referenceDto(Reference $reference): DocumentReferenceDTO
    {
        $dto = new DocumentReferenceDTO();
        $dto->referenceType = $reference->referenceType->value;
        $dto->referenceTypeLabel = $reference->referenceType->label();
        $dto->referenceId = (string) $reference->referenceId;

        return $dto;
    }
}
