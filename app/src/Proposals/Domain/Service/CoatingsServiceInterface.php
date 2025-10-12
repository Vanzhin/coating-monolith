<?php
declare(strict_types=1);

namespace App\Proposals\Domain\Service;

interface CoatingsServiceInterface
{
    public function getPagedCoatings(): CoatingsQueryResult;

    public function getCoating(string $id): CoatingQueryResult;
}