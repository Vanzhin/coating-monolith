<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Shared\Domain\Repository\PaginationResult;

interface GeneralProposalInfoRepositoryInterface
{
    public function add(GeneralProposalInfo $generalProposalInfo): void;

    public function findOneById(string $id): ?Coating;

    public function findOneByNumber(string $number): ?GeneralProposalInfo;


    public function remove(Coating $coating): void;

    public function findByFilter(CoatingsFilter $filter): PaginationResult;

}