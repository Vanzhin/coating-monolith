<?php
declare(strict_types=1);

namespace App\Proposals\Domain\Service;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;

interface CoatingsServiceInterface
{
    public function getPagedCoatings(): GetPagedCoatingsQueryResult;

    public function getCoating(string $id): GetCoatingQueryResult;
}
