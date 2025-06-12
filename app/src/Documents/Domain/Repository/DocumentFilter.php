<?php

declare(strict_types=1);

namespace App\Documents\Domain\Repository;

use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;
use App\Shared\Domain\Repository\Pager;

class DocumentFilter
{
    private ?array $categoryTypes = [];

    public function __construct(
        private ?string $search = null,
        private ?string $title = null,
        private ?string $category = null,
        public ?Pager $pager = null,
    ) {
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function getPager(): ?Pager
    {
        return $this->pager;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    public function getCategoryTypes(): ?array
    {
        return $this->categoryTypes;
    }

    public function addCategoryType(DocumentCategoryType $categoryType): void
    {
        if (!in_array($categoryType, $this->categoryTypes)) {
            $this->categoryTypes[] = $categoryType;
        }
    }

    public function setSearch(?string $search): void
    {
        $this->search = $search;
    }
}