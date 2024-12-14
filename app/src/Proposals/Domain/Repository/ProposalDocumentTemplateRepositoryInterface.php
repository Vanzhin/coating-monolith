<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Repository;

use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocumentTemplate;

interface ProposalDocumentTemplateRepositoryInterface
{
    public function add(ProposalDocumentTemplate $template): void;

    public function findOneById(string $id): ?ProposalDocumentTemplate;

    public function remove(ProposalDocumentTemplate $template): void;

}