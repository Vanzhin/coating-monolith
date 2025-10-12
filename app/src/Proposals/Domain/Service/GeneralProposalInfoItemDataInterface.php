<?php
declare(strict_types=1);

namespace App\Proposals\Domain\Service;

interface GeneralProposalInfoItemDataInterface
{
    public function getCoatId(): ?string;
    public function getCoatNumber(): ?int;
    public function getCoatPrice(): ?float;
    public function getCoatDft(): ?int;
    public function getCoatColor(): ?string;
    public function getThinnerPrice(): ?float;
    public function getThinnerConsumption(): ?int;
    public function getLoss(): ?int;
}
