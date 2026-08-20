<?php

declare(strict_types=1);

namespace App\Coatings\Application\Event;

use App\Coatings\Domain\Compliance\SystemComplianceEvaluator;
use App\Coatings\Domain\Event\CoatingSystemMutated;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Application\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RefreshCacheOnCoatingSystemMutatedHandler implements EventHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemSearchCacheRepository $searchCache,
        private CoatingSystemComplianceCacheRepository $complianceCache,
        private SystemComplianceEvaluator $evaluator,
    ) {
    }

    public function __invoke(CoatingSystemMutated $event): void
    {
        $system = $this->repo->findById(Uuid::fromString($event->systemId));
        if (null === $system) {
            return;
        }
        $this->searchCache->upsert($system);
        $this->complianceCache->rewrite($event->systemId, $this->evaluator->evaluate($system));
    }
}
