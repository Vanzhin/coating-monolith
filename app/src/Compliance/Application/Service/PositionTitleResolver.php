<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Personnel\Application\UseCase\Query\GetPositionsByIds\GetPositionsByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetPositionsByIds\GetPositionsByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/** Резолв названий должностей (id → title) из Personnel через query-шину — для чипов/показа норм. */
final readonly class PositionTitleResolver
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    /**
     * @return array<string, string> id должности → название
     */
    public function titles(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        /** @var GetPositionsByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetPositionsByIdsQuery($ids));

        $map = [];
        foreach ($result->positions as $position) {
            $map[$position->id] = $position->title;
        }

        return $map;
    }
}
