<?php

declare(strict_types=1);

namespace App\Coatings\Application\Event;

use App\Coatings\Application\Service\CoatingSystemOperatingTemperatureCalculator;
use App\Coatings\Domain\Event\TagRenamed;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

final readonly class RefreshCacheOnTagRenamedHandler implements EventHandlerInterface
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private CoatingRepositoryInterface $coatings,
        private CoatingSystemRepositoryInterface $systems,
        private CoatingSystemSearchCacheRepository $searchCache,
        private CoatingSystemOperatingTemperatureCalculator $temperatureCalculator,
    ) {
    }

    public function __invoke(TagRenamed $event): void
    {
        $tagIds = new StringCollection($event->tagId);
        $affected = [];
        $page = 1;
        do {
            $result = $this->coatings->findByFilter(new CoatingsFilter(tagIds: $tagIds, pager: Pager::fromPage($page, self::PAGE_SIZE)));
            foreach ($result->items as $coating) {
                foreach ($this->systems->findByLayerCoatingId($coating->getId()) as $system) {
                    $affected[$system->getId()] = $system;
                }
            }
            ++$page;
        } while (($page - 1) * self::PAGE_SIZE < $result->total);

        $page = 1;
        do {
            $result = $this->systems->findByFilter(new CoatingSystemsFilter(tagIds: $tagIds, pager: Pager::fromPage($page, self::PAGE_SIZE)));
            foreach ($result->items as $system) {
                $affected[$system->getId()] = $system;
            }
            ++$page;
        } while (($page - 1) * self::PAGE_SIZE < $result->total);

        foreach ($affected as $system) {
            $this->searchCache->upsert($system, $this->temperatureCalculator->calculate($system));
        }
    }
}
