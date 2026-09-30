<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Service;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\ValueObject\Cadence;

/**
 * Считает дату следующего срока обязанности (`nextDueAt`) — момент СОБЫТИЯ (выдача / смена cadence), не чтения.
 * Периодическая → от последней выдачи + период; однократно/по факту → срока нет; по документам изготовителя →
 * конкретная дата, введённая на выдаче. Не выдано → срока нет (Red даст резолвер по пустой дате выдачи).
 */
final class ObligationDueCalculator
{
    public function nextDue(
        Cadence $cadence,
        ?\DateTimeImmutable $lastFulfilledAt,
        ?\DateTimeImmutable $manualDueDate,
    ): ?\DateTimeImmutable {
        if (null === $lastFulfilledAt) {
            return null;
        }

        return match ($cadence->kind) {
            CadenceKind::Periodic => $cadence->nextDueFrom($lastFulfilledAt),
            CadenceKind::Once, CadenceKind::ByFact => null,
            CadenceKind::ByManufacturerDoc => $manualDueDate,
        };
    }
}
