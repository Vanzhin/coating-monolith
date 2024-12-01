<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\Proposal\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class GeneralProposalInfoItemSpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueCoatNumberGeneralProposalInfoItemSpecification $uniqueCoatNumberGeneralProposalInfoItemSpecification
    )
    {
    }

}