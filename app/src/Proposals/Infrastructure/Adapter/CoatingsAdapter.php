<?php

declare(strict_types=1);

namespace App\Proposals\Infrastructure\Adapter;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Proposals\Domain\Service\CoatingData;
use App\Proposals\Domain\Service\CoatingQueryResult;
use App\Proposals\Domain\Service\CoatingsQueryResult;
use App\Proposals\Domain\Service\CoatingsServiceInterface;

readonly class CoatingsAdapter implements CoatingsServiceInterface
{
    public function __construct(private CoatingsApiInterface $coatingsApi)
    {
    }

    public function getPagedCoatings(): CoatingsQueryResult
    {
        $result = $this->coatingsApi->getPagedCoatings();

        $coatings = array_map(
            fn(CoatingDTO $coating) => $this->toCoatingData($coating),
            $result->coatings,
        );

        return new CoatingsQueryResult(
            coatings: $coatings,
            totalCount: $result->pager->total_items,
            page: $result->pager->page,
            limit: $result->pager->perPage,
        );
    }

    public function getCoating(string $id): CoatingQueryResult
    {
        $result = $this->coatingsApi->getCoating($id);

        if (!$result->coatingDTO) {
            return new CoatingQueryResult(coatingData: null);
        }

        return new CoatingQueryResult(coatingData: $this->toCoatingData($result->coatingDTO));
    }

    /**
     * Proposals использует плоскую CoatingData (скаляры). Извлекаем из VO-структур
     * первую точку профиля (она же — точка при +20°C для одноточечных профилей).
     */
    private function toCoatingData(CoatingDTO $dto): CoatingData
    {
        return new CoatingData(
            id: $dto->id,
            title: $dto->title,
            description: $dto->description,
            volumeSolid: $dto->volumeSolid,
            massDensity: $dto->massDensity,
            tdsDft: (int) $dto->dftRange['tds_dft'],
            minDft: (int) $dto->dftRange['min'],
            maxDft: (int) $dto->dftRange['max'],
            applicationMinTemp: $dto->applicationMinTemp,
            dryToTouch: $this->firstPointMinutes($dto->dryToTouch),
            minRecoatingInterval: $dto->minRecoatingInterval,
            maxRecoatingInterval: $dto->maxRecoatingInterval,
            fullCure: $this->firstPointMinutes($dto->fullCure),
            pack: $dto->pack,
            thinner: $dto->thinner,
        );
    }

    /**
     * @param list<array{time_in_minutes: float}> $points
     */
    private function firstPointMinutes(array $points): float
    {
        return isset($points[0]) ? (float) $points[0]['time_in_minutes'] : 0.0;
    }
}
