<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\ProposalDocumentTemplate;

class ProposalDocumentTemplateDTO
{
    public ?string $id;
    public ?string $description = null;
    public ?string $path = null;
    public array $availableFormats = [];
}