<?php

declare(strict_types=1);

namespace App\Documents\Domain\Aggregate\Document\ValueObject;

readonly class DocumentTag
{
    public function __construct(private DocumentTitle $title, private DocumentTagType $type = DocumentTagType::DEFAULT)
    {
    }

    public function getTitle(): DocumentTitle
    {
        return $this->title;
    }

    public function getType(): DocumentTagType
    {
        return $this->type;
    }
}