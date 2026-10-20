<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetObligationHistory;

use App\Compliance\Application\DTO\ObligationHistory\ObligationHistoryEventDTO;
use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffActLine;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Лента истории ОДНОЙ позиции (obligationKey): факты выдачи журнала + порции списания, ссылающиеся на них,
 * по дате от свежих к старым. Выдачи — из агрегата {@see ProfileCompliance::recordsForRequirement};
 * списания — из самостоятельного кросс-персон агрегата {@see WriteOffAct} (подписанные акты требования,
 * содержащие строки этого профиля — {@see WriteOffActRepositoryInterface::findSignedForProfileRequirement}),
 * сматченные с выдачами по recordId.
 */
final readonly class GetObligationHistoryQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private WriteOffActRepositoryInterface $writeOffActRepository,
    ) {
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
        foreach ($this->writeOffActRepository->findSignedForProfileRequirement($query->profileId, $query->requirementId) as $act) {
            foreach ($act->lines() as $line) {
                if ($line->profileId() !== $query->profileId) {
                    continue; // строка другого профиля того же кросс-персон акта
                }
                $record = $recordsById[$line->recordId()] ?? null;
                if (null === $record) {
                    continue; // порция списания чужой позиции — не в этой ленте
                }
                $events[] = $this->writeOffEvent($line, $act, $record);
            }
        }

        // Лента — от свежих к старым (по дате, убывание). usort стабилен (PHP 8+): при равенстве дат выдачи
        // остаются раньше списаний той же даты — порядок добавления ($events собран «сперва все выдачи, затем
        // все списания») это гарантирует.
        usort($events, static fn (ObligationHistoryEventDTO $a, ObligationHistoryEventDTO $b): int => $b->date <=> $a->date);

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

    private function writeOffEvent(WriteOffActLine $line, WriteOffAct $act, FulfillmentRecord $record): ObligationHistoryEventDTO
    {
        $unit = $record->quantity()?->unit;

        $event = new ObligationHistoryEventDTO();
        $event->type = ObligationHistoryEventDTO::TYPE_WRITE_OFF;
        $event->date = ($act->actDate() ?? $act->updatedAt())->format('Y-m-d');
        $event->quantityLabel = null !== $unit ? (new Quantity($line->quantity(), $unit))->label() : null;
        $event->reason = $line->reason()?->title();
        $event->actNumber = $act->actNumber();
        $event->actId = $act->getId();

        return $event;
    }
}
