<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\CheckIsChannelVerified;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Users\Domain\Repository\ChannelFilter;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

readonly class CheckIsChannelVerifiedQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ChannelRepositoryInterface $repository,
    ) {
    }

    public function __invoke(CheckIsChannelVerifiedQuery $query): CheckIsChannelVerifiedQueryResult
    {
        //todo сначала обратиться в редис, потом сюда
        $filter = new ChannelFilter();
        $filter->value = $query->channelId;
        $filter->type = $query->type;
        $result = $this->repository->findByFilter($filter);

        if (!$result->items) {
            return new CheckIsChannelVerifiedQueryResult(false);
        }

        return new CheckIsChannelVerifiedQueryResult(true);
    }
}