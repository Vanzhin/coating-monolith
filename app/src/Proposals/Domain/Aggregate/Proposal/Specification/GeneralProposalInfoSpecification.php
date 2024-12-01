<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\Proposal\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class GeneralProposalInfoSpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueNumberGeneralProposalInfoSpecification $uniqueNumberProposalSpecification
    )
    {
    }

}