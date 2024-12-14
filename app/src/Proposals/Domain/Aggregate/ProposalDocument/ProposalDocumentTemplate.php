<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\ProposalDocument;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\UuidService;

class ProposalDocumentTemplate extends Aggregate
{
    private readonly string $id;

    private array $availableFormats = [];

    public function __construct(
        private readonly string $description,
        private readonly string $path,
    )
    {
        $this->id = UuidService::generate();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function addFormat(ProposalDocumentFormat $format): void
    {
        if (!in_array($format->value, $this->availableFormats)) {
            $this->availableFormats[] = $format->value;
        }
    }

    public function getAvailableFormats(): array
    {
        return $this->availableFormats;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}