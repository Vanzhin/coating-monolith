<?php

declare(strict_types=1);

namespace App\Documents\Domain\Repository;

use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;
use App\Shared\Domain\Repository\Pager;

class DocumentFilter implements \JsonSerializable
{
    public const  SEARCH_SEPARATOR = '+';

    private array $categoryTypes = [];
    private ?string $search = null;
    private ?string $title = null;
    private ?string $description = null;
    private ?string $category = null;
    private ?array $products = [];
    public ?Pager $pager = null;
    private ?string $index = null;
    private array $sort = [];
    private ?\DateTimeInterface $createdFrom = null;
    private ?\DateTimeInterface $createdTo = null;

    public function __construct(
        ?string $search = null,
        ?string $title = null,
        ?string $category = null,
        ?Pager $pager = null,
        ?string $index = null,
    ) {
        $this->search = $search;
        $this->title = $title;
        $this->category = $category;
        $this->pager = $pager;
        $this->index = $index;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getProducts(): ?array
    {
        return $this->products;
    }

    public function setProducts(?array $products): self
    {
        $this->products = $products;
        return $this;
    }

    public function getPager(): ?Pager
    {
        return $this->pager;
    }

    public function setPager(?Pager $pager): self
    {
        $this->pager = $pager;
        return $this;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    public function setSearch(?string $search): self
    {
        $this->search = $search;
        return $this;
    }

    public function getCategoryTypes(): array
    {
        return $this->categoryTypes;
    }

    public function addCategoryType(DocumentCategoryType $categoryType): self
    {
        if (!in_array($categoryType, $this->categoryTypes, true)) {
            $this->categoryTypes[] = $categoryType;
        }
        return $this;
    }

    public function setCategoryTypes(array $categoryTypes): self
    {
        $this->categoryTypes = [];
        foreach ($categoryTypes as $type) {
            $this->addCategoryType($type);
        }
        return $this;
    }

    public function getIndex(): ?string
    {
        return $this->index;
    }

    public function setIndex(?string $index): self
    {
        $this->index = $index;
        return $this;
    }

    public function getSort(): array
    {
        return $this->sort;
    }

    public function addSort(string $field, string $direction = 'asc'): self
    {
        $this->sort[$field] = $direction;
        return $this;
    }

    public function getCreatedFrom(): ?\DateTimeInterface
    {
        return $this->createdFrom;
    }

    public function setCreatedFrom(?\DateTimeInterface $date): self
    {
        $this->createdFrom = $date;
        return $this;
    }

    public function getCreatedTo(): ?\DateTimeInterface
    {
        return $this->createdTo;
    }

    public function setCreatedTo(?\DateTimeInterface $date): self
    {
        $this->createdTo = $date;
        return $this;
    }

    public function hasFilters(): bool
    {
        return $this->search !== null
            || $this->title !== null
            || $this->description !== null
            || $this->category !== null
            || !empty($this->products)
            || !empty($this->categoryTypes)
            || $this->createdFrom !== null
            || $this->createdTo !== null;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}