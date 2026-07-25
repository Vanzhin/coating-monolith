<?php

declare(strict_types=1);

namespace App\Documents\Application\DTO\Document;

class DocumentCountByCategoryDTO
{
    /** @var list<DocumentCategoryCount> */
    public array $categories = [];

    public function __construct(DocumentCategoryCount ...$items)
    {
        $this->categories = $items;
    }

    public function getTotalCount(): int
    {
        $count = 0;
        foreach ($this->categories as $category) {
            $count += $category->count;
        }

        return $count;
    }
}
