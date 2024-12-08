<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\GeneralProposalInfoItem;

class GeneralProposalInfoItemDTO
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
}