<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;

class GeneralProposalInfoItem extends Aggregate
{
    private readonly string $id;
    private string $coatId;
    private int $coatNumber;
    private float $coatPrice;
    private int $coatDft;
    private string $coatColor;
    private float $thinnerPrice;
    private int $thinnerConsumption;
    private ?int $loss;
    private readonly GeneralProposalInfo $proposal;


    public function __construct(
        string              $coatId,
        int                 $coatNumber,
        float               $coatPrice,
        int                 $coatDft,
        string              $coatColor,
        float               $thinnerPrice,
        int                 $thinnerConsumption,
        GeneralProposalInfo $proposal,
        ?int                $loss = null,
    )
    {
        $this->id = UuidService::generate();
        $this->coatId = $coatId;
        $this->coatNumber = $coatNumber;
        $this->coatPrice = $coatPrice;
        $this->coatDft = $coatDft;
        $this->coatColor = $coatColor;
        $this->thinnerPrice = $thinnerPrice;
        $this->thinnerConsumption = $thinnerConsumption;
        $this->proposal = $proposal;
        $this->loss = $loss;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCoatId(): string
    {
        return $this->coatId;
    }

    public function setCoatId(string $coatId): void
    {
        $this->coatId = $coatId;
    }

    public function getCoatNumber(): int
    {
        return $this->coatNumber;

    }

    public function setCoatNumber(int $coatNumber): void
    {
        $this->coatNumber = $coatNumber;
        AssertService::greaterThanEq($this->coatNumber, 0);
    }

    public function getCoatPrice(): float
    {
        return $this->coatPrice;
    }

    public function setCoatPrice(float $coatPrice): void
    {
        $this->coatPrice = $coatPrice;
        AssertService::greaterThanEq($this->coatPrice, 0);

    }

    public function getCoatDft(): int
    {
        return $this->coatDft;
    }

    public function setCoatDft(int $coatDft): void
    {
        $this->coatDft = $coatDft;
        AssertService::greaterThanEq($this->coatDft, 0);

    }

    public function getCoatColor(): string
    {
        return $this->coatColor;
    }

    public function setCoatColor(string $coatColor): void
    {
        $this->coatColor = $coatColor;
    }

    public function getThinnerPrice(): float
    {
        return $this->thinnerPrice;
    }

    public function setThinnerPrice(float $thinnerPrice): void
    {
        $this->thinnerPrice = $thinnerPrice;
        AssertService::greaterThanEq($this->thinnerPrice, 0);

    }

    public function getThinnerConsumption(): int
    {
        return $this->thinnerConsumption;
    }

    public function setThinnerConsumption(int $thinnerConsumption): void
    {
        $this->thinnerConsumption = $thinnerConsumption;
        AssertService::greaterThanEq($this->thinnerConsumption, 0);
        AssertService::lessThanEq($thinnerConsumption, 100);
    }

    public function getLoss(): ?int
    {
        return $this->loss;
    }

    public function setLoss(?int $loss): void
    {
        $this->loss = $loss;
    }

    public function getProposal(): GeneralProposalInfo
    {
        return $this->proposal;
    }

}