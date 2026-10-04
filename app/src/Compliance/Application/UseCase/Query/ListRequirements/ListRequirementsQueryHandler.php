<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\ListRequirements;

use App\Compliance\Application\DTO\Requirement\RequirementDTO;
use App\Compliance\Application\DTO\Requirement\RequirementDTOTransformer;
use App\Compliance\Application\Service\PositionTitleResolver;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementsFilter;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

final readonly class ListRequirementsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private RequirementRepositoryInterface $repository,
        private RequirementDTOTransformer $transformer,
        private PositionTitleResolver $positionTitles,
    ) {
    }

    public function __invoke(ListRequirementsQuery $query): ListRequirementsQueryResult
    {
        $pager = $query->pager ?? Pager::fromPage();
        $paginator = $this->repository->findByFilter(new RequirementsFilter($pager));

        /** @var list<Requirement> $requirements */
        $requirements = array_values($paginator->items);

        // Названия должностей всех требований — одним запросом к Personnel.
        $allPositionIds = [];
        foreach ($requirements as $requirement) {
            $allPositionIds = array_merge($allPositionIds, $requirement->getPositionIds()->getList());
        }
        $titles = $this->positionTitles->titles(new StringCollection(...array_values(array_unique($allPositionIds))));

        $dtos = array_map(
            fn (Requirement $requirement): RequirementDTO => $this->transformer->fromEntity($requirement, $titles),
            $requirements,
        );

        return new ListRequirementsQueryResult($dtos, new Pager($pager->page, $pager->perPage, $paginator->total));
    }
}
