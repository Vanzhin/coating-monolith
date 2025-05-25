<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Mapper;

use App\Documents\Domain\Aggregate\Document\Document;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentDescription;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentProduct;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentTitle;
use App\Shared\Domain\Aggregate\ValueObject\Link;
use Symfony\Component\Uid\Uuid;

class DocumentMapper
{
    public function mapEntity(array $data): Document
    {
        $item = new Document(
            new Uuid($data['_id']),
            new DocumentTitle($data['_source']['title']),
            DocumentCategoryType::from($data['_source']['category']),
            new DocumentDescription($data['_source']['description']),
            new Link($data['_source']['url']),
        );
        $item->setCreatedAt(new \DateTimeImmutable($data['_source']['created_at']));
        if (isset($data['_source']['updated_at'])) {
            $item->setUpdatedAt(new \DateTimeImmutable($data['_source']['updated_at']));
        }

        foreach ($data['_source']['products'] as $product) {
            $item->addProduct(new DocumentProduct(
                new DocumentTitle($product['title']),
                $product['product_id'] ? new Uuid($product['product_id']) : null,
            ));
        }

        return $item;
    }
}