<?php
declare(strict_types=1);


namespace App\Proposals\Application\DTO\GeneralProposalInfo;

use App\Proposals\Application\DTO\GeneralProposalInfoItem\GeneralProposalInfoItemDTO;

class GeneralProposalInfoDTO
{
    public ?string $id;
    public ?string $description;
    public string $number;
    public ?string $basis;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $ownerId;
    public ?string $unit;
    public ?string $projectTitle;
    public ?float $projectArea;
    public ?string $projectStructureDescription;
    public ?int $loss;
    public ?string $durability;
    public ?string $category;
    public ?string $treatment;
    public ?string $method;
    /**
     * @var GeneralProposalInfoItemDTO[]
     */
    public array $coats;
}