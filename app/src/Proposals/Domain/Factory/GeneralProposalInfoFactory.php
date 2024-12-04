<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Factory;

use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoUnit;
use App\Proposals\Domain\Aggregate\Proposal\Specification\GeneralProposalInfoSpecification;

readonly class GeneralProposalInfoFactory
{
    public function __construct(private GeneralProposalInfoSpecification $generalProposalInfoSpecification)
    {
    }

    public function create(
        string  $number,
        string  $ownerId,
        string  $unit,
        string  $projectTitle,
        float   $projectArea,
        ?string $description,
        ?string $basis,
        ?string $projectStructureDescription,
        ?string $durability,
        ?string $category,
        ?string $treatment,
        ?string $method,
        int     $loss,
    ): GeneralProposalInfo
    {
        return new GeneralProposalInfo(
            $number,
            $ownerId,
            GeneralProposalInfoUnit::from($unit),
            $projectTitle,
            $projectArea,
            $this->generalProposalInfoSpecification,
            $description,
            $basis,
            $projectStructureDescription,
            CoatingSystemDurability::from($durability),
            CoatingSystemCorrosiveCategory::from($category),
            CoatingSystemSurfaceTreatment::from($treatment),
            CoatingSystemApplicationMethod::from($method),
            $loss,
        );
    }
}