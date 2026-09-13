<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatings;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;
use App\Coatings\Application\DTO\Coatings\MixingRatioDTO;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\MixingRatio;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

/**
 * Лёгкий постраничный поиск покрытий для typeahead. Структурно — один-в-один с
 * GetPagedCoatingsQueryHandler (findByFilter → items + Pager из фильтра), НО строит лёгкие
 * CoatingSuggestDTO (без тяжёлых связей: тегов/цветов/систем/химстойкости) и НЕ обогащает
 * подсветкой веществ. Единственная разница с GetPagedCoatings — вес данных.
 */
readonly class SearchCoatingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private CoatingRepositoryInterface $coatingRepository)
    {
    }

    public function __invoke(SearchCoatingsQuery $query): SearchCoatingsQueryResult
    {
        $paginator = $this->coatingRepository->findByFilter($query->filter);

        $coatings = array_map(
            fn (Coating $coating): CoatingSuggestDTO => $this->toSuggestDto($coating),
            array_values($paginator->items),
        );

        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new SearchCoatingsQueryResult($coatings, $pager);
    }

    private function toSuggestDto(Coating $coating): CoatingSuggestDTO
    {
        $dftMin = (int) $coating->getDftRange()->range->getMin();
        $dftMax = (int) $coating->getDftRange()->range->getMax();
        $base = $coating->getBase()->value;

        $dto = new CoatingSuggestDTO();
        $dto->id = $coating->getId();
        $dto->title = sprintf('%s (%s, %d–%d мкм)', $coating->getTitle(), $base, $dftMin, $dftMax);
        $dto->base = $base;
        $dto->dftMin = $dftMin;
        $dto->dftMax = $dftMax;
        $dto->volumeSolid = $coating->getVolumeSolid();
        $dto->mixingRatio = $this->mixingRatioDto($coating->getMixingRatio());

        return $dto;
    }

    private function mixingRatioDto(?MixingRatio $mixingRatio): ?MixingRatioDTO
    {
        if (null === $mixingRatio) {
            return null;
        }

        $dto = new MixingRatioDTO();
        $dto->volume = $mixingRatio->getByVolume()?->getParts();
        $dto->mass = $mixingRatio->getByMass()?->getParts();

        return $dto;
    }
}
