<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Данные просмотра КОНКРЕТНОГО подписанного акта выдачи (слепок): реквизиты + позиции именно этого документа
 * (что выдано: наименование/модель/кол-во/дата + на руках/списание по его записям). Общий для страницы акта
 * ({@see \App\Compliance\Infrastructure\Controller\Fulfillment\IssueAction}) и модалки-превью. Read-only.
 */
final readonly class ComplianceActViewBuilder
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    /**
     * @return array{profile: \App\Personnel\Application\DTO\Profile\ProfileDTO, requirementName: string, act: array{number: string|null, date: string|null, responsible: string|null}, rows: list<array<string, mixed>>}|null
     */
    public function build(string $profileId, string $requirementId, string $documentId): ?array
    {
        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($profileId));
        $profileCompliance = $this->repository->findByProfile($profileId);
        if (null === $profileResult->profile || null === $profileCompliance) {
            return null;
        }

        $document = null;
        foreach ($profileCompliance->signedDocumentsFor($requirementId) as $doc) {
            if ($doc->getId() === $documentId) {
                $document = $doc;
                break;
            }
        }
        if (null === $document) {
            return null; // не этот профиль/требование или ещё не подписан
        }

        /** @var GetProfileComplianceQueryResult $complianceResult */
        $complianceResult = $this->queryBus->execute(new GetProfileComplianceQuery($profileId));
        $obligations = null !== $complianceResult->compliance ? $complianceResult->compliance->obligations : [];
        $metaByKey = [];
        $requirementName = 'Требование';
        foreach ($obligations as $o) {
            if ($o->requirementId === $requirementId) {
                $requirementName = $o->requirementName;
                $metaByKey[$o->key] = $o->cadenceLabel;
            }
        }

        // Списания (подписанные) по записям — чтобы показать «списано N (акт № …)» у позиций этого акта
        // ссылкой на сам акт списания (actId).
        $writeOffsByRecord = [];
        foreach ($profileCompliance->getWriteOffActs() as $act) {
            if ($act->requirementId() !== $requirementId || !$act->isSigned()) {
                continue;
            }
            foreach ($profileCompliance->itemsOfWriteOffAct($act->getId()) as $portion) {
                $writeOffsByRecord[$portion->recordId()][] = ['actId' => $act->getId(), 'actNumber' => $act->actNumber(), 'qty' => $portion->quantity()];
            }
        }

        $rows = [];
        foreach ($profileCompliance->recordsForRequirement($requirementId) as $record) {
            if ($record->documentId() !== $documentId) {
                continue; // запись другого акта
            }
            $key = $record->obligationKey();
            $quantity = $record->quantity();
            $unit = $quantity?->unit->title() ?? '';
            $writeOffs = [];
            foreach ($writeOffsByRecord[$record->getId()] ?? [] as $w) {
                $writeOffs[] = [
                    'actId' => $w['actId'],
                    'label' => 'списано '.$this->trimmed($w['qty']).(null !== $w['actNumber'] && '' !== $w['actNumber'] ? ' (акт № '.$w['actNumber'].')' : ''),
                ];
            }
            $rows[] = [
                'label' => $profileCompliance->obligationLabelOf($key),
                'model' => $record->note(),
                'meta' => $metaByKey[$key] ?? '',
                'issued' => null !== $quantity ? trim($this->trimmed($quantity->amount).' '.$unit) : '',
                'date' => $record->fulfilledAt()->format('d.m.Y'),
                'due' => $record->manualDueDate()?->format('d.m.Y'),
                'held' => null !== $quantity ? trim($this->trimmed($record->heldAmount()).' '.$unit) : '',
                'writeOffs' => $writeOffs,
            ];
        }

        return [
            'profile' => $profileResult->profile,
            'requirementName' => $requirementName,
            'act' => [
                'number' => $document->actNumber(),
                'date' => $document->signedAt()?->format('d.m.Y'),
                'responsible' => $document->responsibleFio(),
            ],
            'rows' => $rows,
        ];
    }

    /** Кол-во без лишних нулей: целое → без дробной части (как форматирование количеств в карточке). */
    private function trimmed(float $amount): string
    {
        return 0.0 === fmod($amount, 1.0) ? (string) (int) $amount : (string) $amount;
    }
}
