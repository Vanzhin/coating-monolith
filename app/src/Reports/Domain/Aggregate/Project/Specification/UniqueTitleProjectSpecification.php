<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Project\Specification;

use App\Reports\Domain\Aggregate\Project\Project;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Domain\Specification\SpecificationInterface;
use App\Shared\Infrastructure\Exception\AppException;

class UniqueTitleProjectSpecification implements SpecificationInterface
{
    public function __construct(private readonly ProjectRepositoryInterface $repository)
    {
    }

    public function satisfy(Project $project): void
    {
        $exist = $this->repository->findOneByTitle($project->getTitle());
        if (null !== $exist && $exist->getId() !== $project->getId()) {
            throw new AppException(sprintf('Проект с названием «%s» уже существует.', $project->getTitle()));
        }
    }
}
