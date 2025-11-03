<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\CheckIsChannelVerified;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Users\Domain\Entity\Channel;
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
        $filter->value = $query->channelValue;
        $filter->type = $query->type;
        $result = $this->repository->findByFilter($filter);

        /** @var Channel $channel */
        $channel = current($result->items) ?? null;

        if (!$channel) {
            return new CheckIsChannelVerifiedQueryResult(false);
        }

        return new CheckIsChannelVerifiedQueryResult($channel->isVerified());
    }
}