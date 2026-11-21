<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetOwnDriverProfile;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;
use App\Waybills\Application\Service\AccessControl\DriverProfileAccessControl;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use App\Waybills\Domain\Repository\ProfileFilter;

final readonly class GetOwnDriverProfileQueryHandler implements QueryHandlerInterface
{
    public function __construct(private DriverProfileRepositoryInterface $profiles, private DriverProfileAccessControl $access)
    {
    }

    public function __invoke(GetOwnDriverProfileQuery $query): GetOwnDriverProfileQueryResult
    {
        $result = $this->profiles->findByFilter(new ProfileFilter($this->access->currentUserId(), Pager::fromPage(1, 1)));
        $profile = $result->items[0] ?? null;
        assert(null === $profile || $profile instanceof DriverProfile);

        return new GetOwnDriverProfileQueryResult($profile?->getId(), $profile?->getDriverDetails(), $profile?->getRouteSettings());
    }
}
