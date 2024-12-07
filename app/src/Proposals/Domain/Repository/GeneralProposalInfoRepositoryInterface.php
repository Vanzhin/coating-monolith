<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Repository;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Shared\Domain\Repository\PaginationResult;

interface GeneralProposalInfoRepositoryInterface
{
    public function add(GeneralProposalInfo $generalProposalInfo): void;

    public function findOneById(string $id): ?GeneralProposalInfo;

    public function findOneByNumber(string $number): ?GeneralProposalInfo;


    public function remove(GeneralProposalInfo $generalProposalInfo): void;

    public function findByFilter(GeneralProposalInfoFilter $filter): PaginationResult;

}