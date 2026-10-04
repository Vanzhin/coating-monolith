<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Service;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\ValueObject\Cadence;

/**
 * Считает дату следующего срока обязанности (`nextDueAt`) — момент СОБЫТИЯ (выдача / смена cadence), не чтения.
 * Периодическая → от последней выдачи + период; однократно → срока нет; «до износа» → крайняя дата с выдачи, а
 * без неё — предел «не более N» (выдача + N), иначе срока нет; по документам изготовителя → дата с выдачи.
 * Не выдано → срока нет (Red даст резолвер по пустой дате выдачи).
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
            CadenceKind::Once => null,
            CadenceKind::ByFact => $manualDueDate ?? $cadence->nextDueFrom($lastFulfilledAt),
            CadenceKind::ByManufacturerDoc => $manualDueDate,
        };
    }
}
