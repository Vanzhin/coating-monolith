<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Adapter;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Proposals\Domain\Service\CoatingsServiceInterface;

readonly class CoatingsAdapter implements CoatingsServiceInterface
{
    public function __construct(private CoatingsApiInterface $coatingsApi)
    {
    }

    public function getPagedCoatings(): GetPagedCoatingsQueryResult
    {
        return $this->coatingsApi->getPagedCoatings();
    }

    public function getCoating(string $id): GetCoatingQueryResult
    {
        return $this->coatingsApi->getCoating($id);
    }

}