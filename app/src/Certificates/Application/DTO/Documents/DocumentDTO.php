<?php

declare(strict_types=1);

namespace App\Certificates\Application\DTO\Documents;

class DocumentDTO
{
    public string $id;
    public string $kind;
    public string $kindLabel;
    public string $title;
    public string $issuerId;
    public ?string $issuerTitle = null;
    public \DateTimeImmutable $issuedAt;
    public ?\DateTimeImmutable $expiresAt = null;
    public ?string $testStandard = null;
    public string $subject;
    public ?string $description = null;
    public ?string $file = null;
    public bool $hasFile = false;
    public bool $isExpired = false;
    /** @var list<DocumentReferenceDTO> */
    public array $references = [];
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
}
