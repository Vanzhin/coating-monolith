<?php

declare(strict_types=1);

namespace App\Documents\Domain\Aggregate\Document;

use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentDescription;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentProduct;
use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentTitle;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\ValueObject\Link;
use Symfony\Component\Uid\Uuid;

class Document extends Aggregate
{
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $updatedAt;

    /**
     * @var DocumentProduct[] $products
     */
    private array $products = [];

    public function __construct(
        private readonly Uuid $id,
        private readonly DocumentTitle $title,
        private readonly DocumentCategoryType $type,
        private readonly DocumentDescription $description,
        private readonly Link $link,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id->jsonSerialize();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getLink(): Link
    {
        return $this->link;
    }

    public function getProducts(): array
    {
        return $this->products;
    }

    public function getTitle(): DocumentTitle
    {
        return $this->title;
    }

    public function getType(): DocumentCategoryType
    {
        return $this->type;
    }

    public function getDescription(): DocumentDescription
    {
        return $this->description;
    }

    public function addProduct(DocumentProduct $product): void
    {
        if (!in_array($product, $this->products, true)) {
            $this->products[] = $product;
        }
    }

}