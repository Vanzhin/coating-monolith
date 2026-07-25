<?php

declare(strict_types=1);

namespace App\Documents\Domain\Factory;

use App\Documents\Application\DTO\Document\DocumentProductDTO;
use App\Documents\Domain\Aggregate\Document\Document;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentDescription;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentProduct;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentTitle;
use App\Shared\Domain\Aggregate\ValueObject\Link;
use Symfony\Component\Uid\Uuid;

readonly class DocumentFactory
{
    /**
     * @param list<DocumentProductDTO>|null $products
     */
    public function create(
        string $title,
        string $description,
        string $category,
        string $link,
        ?array $products,
    ): Document {
        $document = new Document(
            Uuid::v4(),
            new DocumentTitle($title),
            DocumentCategoryType::from($category),
            new DocumentDescription($description),
            new Link($link)
        );
        foreach ($products ?? [] as $product) {
            $item = new DocumentProduct(new DocumentTitle((string) $product->title), new Uuid((string) $product->id));
            $document->addProduct($item);
        }

        return $document;
    }
}
