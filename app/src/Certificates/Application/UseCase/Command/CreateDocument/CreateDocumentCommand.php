<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\CreateDocument;

use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Shared\Application\Command\Command;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class CreateDocumentCommand extends Command
{
    /**
     * @param list<Reference> $references
     */
    public function __construct(
        public DocumentKind $kind,
        public string $title,
        public string $issuerId,
        public \DateTimeImmutable $issuedAt,
        public ?\DateTimeImmutable $expiresAt,
        public string $subject,
        public ?string $description,
        public ?string $testStandard,
        public array $references,
        public ?UploadedFile $file = null,
    ) {
    }
}
