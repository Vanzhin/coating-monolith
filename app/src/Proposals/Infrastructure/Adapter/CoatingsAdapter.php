<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Adapter;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQueryResult as CoatingsGetCoatingQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult as CoatingsGetPagedCoatingsQueryResult;
use App\Proposals\Domain\Service\CoatingsServiceInterface;
use App\Proposals\Domain\Service\CoatingsQueryResult;
use App\Proposals\Domain\Service\CoatingQueryResult;
use App\Proposals\Domain\Service\CoatingData;

readonly class CoatingsAdapter implements CoatingsServiceInterface
{
    public function __construct(private CoatingsApiInterface $coatingsApi)
    {
    }

    public function getPagedCoatings(): CoatingsQueryResult
    {
        $result = $this->coatingsApi->getPagedCoatings();
        
        $coatings = [];
        foreach ($result->coatings as $coating) {
            $coatings[] = new CoatingData(
                id: $coating->id,
                title: $coating->title,
                description: $coating->description,
                volumeSolid: $coating->volumeSolid,
                massDensity: $coating->massDensity,
                tdsDft: $coating->tdsDft,
                minDft: $coating->minDft,
                maxDft: $coating->maxDft,
                applicationMinTemp: $coating->applicationMinTemp,
                dryToTouch: $coating->dryToTouch,
                minRecoatingInterval: $coating->minRecoatingInterval,
                maxRecoatingInterval: $coating->maxRecoatingInterval,
                fullCure: $coating->fullCure,
                pack: $coating->pack,
                thinner: $coating->thinner
            );
        }
        
        return new CoatingsQueryResult(
            coatings: $coatings,
            totalCount: $result->pager->total_items,
            page: $result->pager->page,
            limit: $result->pager->perPage
        );
    }

    public function getCoating(string $id): CoatingQueryResult
    {
        $result = $this->coatingsApi->getCoating($id);
        
        if (!$result->coatingDTO) {
            return new CoatingQueryResult(coatingData: null);
        }
        
        $coatingData = new CoatingData(
            id: $result->coatingDTO->id,
            title: $result->coatingDTO->title,
            description: $result->coatingDTO->description,
            volumeSolid: $result->coatingDTO->volumeSolid,
            massDensity: $result->coatingDTO->massDensity,
            tdsDft: $result->coatingDTO->tdsDft,
            minDft: $result->coatingDTO->minDft,
            maxDft: $result->coatingDTO->maxDft,
            applicationMinTemp: $result->coatingDTO->applicationMinTemp,
            dryToTouch: $result->coatingDTO->dryToTouch,
            minRecoatingInterval: $result->coatingDTO->minRecoatingInterval,
            maxRecoatingInterval: $result->coatingDTO->maxRecoatingInterval,
            fullCure: $result->coatingDTO->fullCure,
            pack: $result->coatingDTO->pack,
            thinner: $result->coatingDTO->thinner
        );
        
        return new CoatingQueryResult(coatingData: $coatingData);
    }

}