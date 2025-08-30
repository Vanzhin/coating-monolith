<?php

declare(strict_types=1);

namespace App\Documents\Application\DTO\Document;

class DocumentDTO
{
    public ?string $id;
    public ?string $title;
    public ?string $category;
    public ?string $description;
    public ?string $link;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?array $products;
    public ?array $tags;
}