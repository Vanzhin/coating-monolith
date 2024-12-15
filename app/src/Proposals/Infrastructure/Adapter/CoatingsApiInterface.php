<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Adapter;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;

interface CoatingsApiInterface
{
    public function getPagedCoatings(): GetPagedCoatingsQueryResult;

    public function getCoating(string $id): GetCoatingQueryResult;
}