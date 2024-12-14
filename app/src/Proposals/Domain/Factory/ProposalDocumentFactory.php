<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Factory;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocument;
use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocumentFormat;
use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocumentTemplate;

readonly class ProposalDocumentFactory
{
    public function create(
        ProposalDocumentTemplate $template,
        GeneralProposalInfo      $proposalInfo,
        string                   $format,
    ): ProposalDocument
    {
        return new ProposalDocument(
            $template,
            $proposalInfo,
            ProposalDocumentFormat::from($format)
        );

    }
}