<?php

declare(strict_types=1);

namespace App\Documents\Domain\Aggregate\Document\ValueObject;

use Symfony\Component\Uid\Uuid;

readonly class DocumentProduct
{
    public function __construct(private DocumentTitle $title, private ?Uuid $id)
    {
    }

    public function getTitle(): DocumentTitle
    {
        return $this->title;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }
}