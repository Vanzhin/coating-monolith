<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\ProposalDocument;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\UuidService;

class ProposalDocument extends Aggregate
{
    private readonly string $id;
    private readonly \DateTimeImmutable $createdAt;


    public function __construct(
        private readonly ProposalDocumentTemplate $template,
        private readonly GeneralProposalInfo      $proposalInfo,
        private readonly ProposalDocumentFormat   $format
    )
    {
        $this->id = UuidService::generate();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTemplate(): ProposalDocumentTemplate
    {
        return $this->template;
    }

    public function getProposalInfo(): GeneralProposalInfo
    {
        return $this->proposalInfo;
    }

    public function getFormat(): ProposalDocumentFormat
    {
        return $this->format;
    }
}