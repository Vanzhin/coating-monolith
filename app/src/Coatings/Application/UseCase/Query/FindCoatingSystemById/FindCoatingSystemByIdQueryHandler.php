<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\FindCoatingSystemById;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Service\SystemCertificatesGateway;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

readonly class FindCoatingSystemByIdQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repository,
        private CoatingSystemComplianceCacheRepository $complianceCache,
        private CoatingSystemDTOTransformer $transformer,
        private SystemCertificatesGateway $certificates,
    ) {
    }

    public function __invoke(FindCoatingSystemByIdQuery $query): ?CoatingSystemDTO
    {
        $id = Uuid::fromString($query->id);
        $system = $this->repository->findById($id);
        if (null === $system) {
            return null;
        }

        $documentCount = $this->certificates->countBySystemIds(new StringCollection($query->id))[$query->id] ?? 0;

        return $this->transformer->fromEntity($system, $this->complianceCache->findBySystem($query->id), $documentCount);
    }
}
