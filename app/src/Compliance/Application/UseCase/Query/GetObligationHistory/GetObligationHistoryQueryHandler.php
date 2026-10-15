<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetObligationHistory;

use App\Compliance\Application\DTO\ObligationHistory\ObligationHistoryEventDTO;
use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffItem;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Лента истории ОДНОЙ позиции (obligationKey): факты выдачи журнала + порции списания, ссылающиеся на них,
 * хронологически (от старых к новым). Источник — агрегат {@see ProfileCompliance}: {@see ProfileCompliance::recordsForRequirement}
 * (выдачи) и {@see ProfileCompliance::itemsOfWriteOffAct} (порции списания), сматченные с выдачами по recordId.
 */
final readonly class GetObligationHistoryQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ProfileComplianceRepositoryInterface $repository)
    {
    }

    public function __invoke(GetObligationHistoryQuery $query): GetObligationHistoryQueryResult
    {
        $profileCompliance = $this->repository->findByProfile($query->profileId);
        if (null === $profileCompliance) {
            return new GetObligationHistoryQueryResult([]);
        }

        $records = array_values(array_filter(
            $profileCompliance->recordsForRequirement($query->requirementId),
            static fn (FulfillmentRecord $r): bool => $r->obligationKey() === $query->obligationKey,
        ));
        /** @var array<string, FulfillmentRecord> $recordsById */
        $recordsById = [];
        foreach ($records as $record) {
            $recordsById[$record->getId()] = $record;
        }

        $events = [];
        foreach ($records as $record) {
            $events[] = $this->issuedEvent($record);
        }
        foreach ($profileCompliance->getWriteOffActs() as $act) {
            if ($act->requirementId() !== $query->requirementId) {
                continue;
            }
            foreach ($profileCompliance->itemsOfWriteOffAct($act->getId()) as $item) {
                $record = $recordsById[$item->recordId()] ?? null;
                if (null === $record) {
                    continue; // порция списания чужой позиции — не в этой ленте
                }
                $events[] = $this->writeOffEvent($item, $act, $record);
            }
        }

        // usort стабилен (PHP 8+): при равенстве дат выдачи остаются раньше списаний той же даты — порядок
        // добавления ($events собран «сперва все выдачи, затем все списания») это гарантирует.
        usort($events, static fn (ObligationHistoryEventDTO $a, ObligationHistoryEventDTO $b): int => $a->date <=> $b->date);

        return new GetObligationHistoryQueryResult($events);
    }

    private function issuedEvent(FulfillmentRecord $record): ObligationHistoryEventDTO
    {
        $event = new ObligationHistoryEventDTO();
        $event->type = ObligationHistoryEventDTO::TYPE_ISSUED;
        $event->date = $record->fulfilledAt()->format('Y-m-d');
        $event->quantityLabel = $record->quantity()?->label();
        $event->wearPercent = $record->wearPercent()?->value();
        $event->note = $record->note();

        return $event;
    }

    private function writeOffEvent(WriteOffItem $item, WriteOffAct $act, FulfillmentRecord $record): ObligationHistoryEventDTO
    {
        $unit = $record->quantity()?->unit;

        $event = new ObligationHistoryEventDTO();
        $event->type = ObligationHistoryEventDTO::TYPE_WRITE_OFF;
        $event->date = ($act->actDate() ?? $act->updatedAt())->format('Y-m-d');
        $event->quantityLabel = null !== $unit ? (new Quantity($item->quantity(), $unit))->label() : null;
        $event->reason = $item->reason()?->title();
        $event->actNumber = $act->actNumber();
        $event->actId = $act->getId();
        $event->actSigned = $act->isSigned();

        return $event;
    }
}
