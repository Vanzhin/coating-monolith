<?php

declare(strict_types=1);

namespace App\Documents\Application\DTO\Document;

use App\Documents\Domain\Aggregate\Document\ValueObject\DocumentCategoryType;

class DocumentCategoryCount
{
    public function __construct(public DocumentCategoryType $category, public int $count)
    {
    }
}