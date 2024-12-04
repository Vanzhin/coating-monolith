<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Adapter;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;

interface CoatingsApiInterface
{
    public function getPagedCoatings(): GetPagedCoatingsQueryResult;
}