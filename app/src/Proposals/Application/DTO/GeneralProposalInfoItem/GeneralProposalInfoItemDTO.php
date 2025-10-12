<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\GeneralProposalInfoItem;

use App\Proposals\Domain\Service\GeneralProposalInfoItemDataInterface;

class GeneralProposalInfoItemDTO implements GeneralProposalInfoItemDataInterface
{
    public ?string $id = null;
    public ?string $coatId = null;
    public ?float $coatPrice = null;
    public ?int $coatNumber = null;
    public ?int $coatDft = null;
    public ?string $coatColor = null;
    public ?float $thinnerPrice = null;
    public ?int $thinnerConsumption = null;
    public ?int $loss = null;
    public ?string $proposalId = null;

    public function getCoatId(): ?string
    {
        return $this->coatId;
    }

    public function getCoatNumber(): ?int
    {
        return $this->coatNumber;
    }

    public function getCoatPrice(): ?float
    {
        return $this->coatPrice;
    }

    public function getCoatDft(): ?int
    {
        return $this->coatDft;
    }

    public function getCoatColor(): ?string
    {
        return $this->coatColor;
    }

    public function getThinnerPrice(): ?float
    {
        return $this->thinnerPrice;
    }

    public function getThinnerConsumption(): ?int
    {
        return $this->thinnerConsumption;
    }

    public function getLoss(): ?int
    {
        return $this->loss;
    }
}