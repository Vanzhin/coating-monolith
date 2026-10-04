<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\CalculateNextDue;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Shared\Application\Query\QueryHandlerInterface;

/** Считает следующий срок доменом ({@see Cadence::nextDueFrom}) — единый источник арифметики дат. */
final readonly class CalculateNextDueQueryHandler implements QueryHandlerInterface
{
    public function __invoke(CalculateNextDueQuery $query): CalculateNextDueQueryResult
    {
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $query->date);
        $kind = CadenceKind::tryFrom($query->cadenceKind);
        $unit = null !== $query->unit ? PeriodUnit::tryFrom($query->unit) : null;

        if (false === $base || CadenceKind::Periodic !== $kind || null === $query->number || null === $unit) {
            return new CalculateNextDueQueryResult(null);
        }

        $next = (new Cadence($kind, $query->number, $unit))->nextDueFrom($base);

        return new CalculateNextDueQueryResult($next?->format('Y-m-d'));
    }
}
