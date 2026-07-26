<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\FindCoatingSystemById;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

readonly class FindCoatingSystemByIdQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repository,
        private CoatingSystemDTOTransformer $transformer,
    ) {
    }

    public function __invoke(FindCoatingSystemByIdQuery $query): ?CoatingSystemDTO
    {
        $system = $this->repository->findById(Uuid::fromString($query->id));
        if (null === $system) {
            return null;
        }

        return $this->transformer->fromEntity($system);
    }
}
