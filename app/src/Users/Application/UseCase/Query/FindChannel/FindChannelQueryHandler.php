<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\FindChannel;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Users\Application\DTO\Channel\ChannelDTOTransformer;
use App\Users\Application\Service\AccessControl\ChannelAccessControl;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

readonly class FindChannelQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ChannelRepositoryInterface $repository,
        private ChannelAccessControl $accessControl,
        private ChannelDTOTransformer $dtoTransformer
    ) {
    }

    public function __invoke(FindChannelQuery $query): FindChannelQueryResult
    {
        $channel = $this->repository->findById($query->channelId);
        if (!$channel) {
            return new FindChannelQueryResult(null);
        }
        if (!$this->accessControl->canView($channel)) {
            return new FindChannelQueryResult(null);
        }
        $this->dtoTransformer->fromEntity($channel);

        return new FindChannelQueryResult($this->dtoTransformer->fromEntity($channel));
    }
}