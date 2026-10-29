<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingSystemsFilterAxisLabels;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterAxis;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetCoatingSystemsFilterAxisLabelsQueryHandler implements QueryHandlerInterface
{
    /**
     * @return array<string, string> ключ оси → русская подпись
     */
    public function __invoke(GetCoatingSystemsFilterAxisLabelsQuery $query): array
    {
        return CoatingSystemsFilterAxis::labels();
    }
}
