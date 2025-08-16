<?php

declare(strict_types=1);

namespace App\Documents\Application\DTO\Document;

use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;

class DocumentCountByCategoryDTOTransformer
{

    public function fromArray(array $items): DocumentCountByCategoryDTO
    {
        $categories = [];
        foreach (DocumentCategoryType::array() as $rusName => $value) {
            $categories[] = new DocumentCategoryCount(
                DocumentCategoryType::from($rusName),
                $items[$rusName] ?? 0
            );
        }

        return new DocumentCountByCategoryDTO(...$categories);
    }
}