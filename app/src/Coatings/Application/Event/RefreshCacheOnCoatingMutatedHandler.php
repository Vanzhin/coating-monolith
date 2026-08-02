<?php

declare(strict_types=1);

namespace App\Coatings\Application\Event;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Event\CoatingMutated;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Application\Event\EventHandlerInterface;

final readonly class RefreshCacheOnCoatingMutatedHandler implements EventHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemSearchCacheRepository $searchCache,
        private CoatingSystemComplianceCacheRepository $complianceCache,
        private ComplianceEvaluator $evaluator,
    ) {
    }

    public function __invoke(CoatingMutated $event): void
    {
        foreach ($this->repo->findByLayerCoatingId($event->coatingId) as $system) {
            $this->searchCache->upsert($system);
            $this->complianceCache->rewrite($system, $this->evaluator);
        }
    }
}
