<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestProfiles;

use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\DTO\Profile\ProfileDTOTransformer;
use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\Repository\ProfilesFilter;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

final readonly class SuggestProfilesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private ProfileDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestProfilesQuery $query): SuggestProfilesQueryResult
    {
        $result = $this->repository->findByFilter(new ProfilesFilter(
            pager: Pager::fromPage(1, $query->limit),
            organizationIds: null !== $query->organizationId ? new StringCollection($query->organizationId) : new StringCollection(),
            search: $query->query,
        ));
        /** @var list<Profile> $profiles */
        $profiles = $result->items;

        return new SuggestProfilesQueryResult(array_map(
            fn (Profile $p): ProfileDTO => $this->transformer->fromEntity($p),
            $profiles,
        ));
    }
}
