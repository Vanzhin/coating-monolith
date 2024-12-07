<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\GeneralProposalInfoItem;

class GeneralProposalInfoItemDTO
{
    public string $id;
    public string $coatId;
    public ?float $coatPrice;
    public ?int $coatNumber;
    public ?int $coatDft;
    public ?string $coatColor;
    public ?float $thinnerPrice;
    public ?int $thinnerConsumption;
    public ?int $loss;
    public ?string $proposalId;
}