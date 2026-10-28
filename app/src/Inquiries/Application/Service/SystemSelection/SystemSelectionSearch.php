<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\SystemSelection;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Inquiries\Application\Exception\CoatingSearchFailedException;
use App\Inquiries\Application\Exception\ParametersNotRecognizedException;
use App\Inquiries\Domain\Aggregate\Inquiry\AnswerSnapshot;
use App\Inquiries\Domain\Aggregate\Inquiry\FoundSystem;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Один путь «параметры → снимок ответа»: гейт пустоты + поиск систем Coatings (query-шина) + сборка
 * снимка. Общий для движка (первичный интейк) и переподбора после правки инженером — чтобы гейты и
 * типизированные ошибки жили в одном месте.
 */
final readonly class SystemSelectionSearch
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function search(CoatingSystemsFilterInput $input): AnswerSnapshot
    {
        if (!$input->hasAnyConstraint()) {
            throw new ParametersNotRecognizedException();
        }

        try {
            $result = $this->queryBus->execute(SearchCoatingSystemsQuery::fromParameters($input));
        } catch (\Throwable $e) {
            throw new CoatingSearchFailedException($e);
        }
        \assert($result instanceof SearchCoatingSystemsQueryResult);

        $systems = array_map(
            static fn (CoatingSystemDTO $dto): FoundSystem => new FoundSystem($dto->id, $dto->title),
            $result->items,
        );

        return new AnswerSnapshot($result->total, ...$systems);
    }
}
