<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\CommercialProposal;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;
use App\Users\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class CommercialProposalItem extends Aggregate
{
    private readonly string $id;


    public function __construct(
        string               $title,
        string               $description,
        int                  $volumeSolid,
        float                $massDensity,
        int                  $tdsDft,
        int                  $minDft,
        int                  $maxDft,
        int                  $applicationMinTemp,
        float                $dryToTouch,
        float                $minRecoatingInterval,
        float                $maxRecoatingInterval,
        float                $fullCure,
        float                $pack,
        ?string              $thinner,
        Manufacturer         $manufacturer,
        CoatingSpecification $specification
    )
    {
        $this->id = UuidService::generate();
    }

    public function getId(): string
    {
        return $this->id;
    }

}