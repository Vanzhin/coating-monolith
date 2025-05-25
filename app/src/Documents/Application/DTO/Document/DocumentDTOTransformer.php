<?php

declare(strict_types=1);

namespace App\Documents\Application\DTO\Document;

use App\Documents\Domain\Aggregate\Document\Document;

class DocumentDTOTransformer
{
    public function fromEntity(Document $document): DocumentDTO
    {
        $products = [];
        foreach ($document->getProducts() as $product) {
            $item = new DocumentProductDTO();
            $item->id = $product->getId()?->jsonSerialize();
            $item->title = $product->getTitle()->getValue();
            $products[] = $item;
        }

        $dto = new DocumentDTO();
        $dto->id = $document->getId();
        $dto->createdAt = $document->getCreatedAt()->format(DATE_ATOM);
        $dto->updatedAt = $document->getUpdatedAt()?->format(DATE_ATOM);
        $dto->link = $document->getLink()->getValue();
        $dto->title = $document->getTitle()->getValue();
        $dto->description = $document->getDescription()->getValue();
        $dto->products = $products;

        return $dto;
    }

    public function fromEntityList(array $documents): array
    {
        $documentDTOs = [];
        foreach ($documents as $document) {
            $documentDTOs[] = $this->fromEntity($document);
        }

        return $documentDTOs;
    }

}