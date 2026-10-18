<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\ProfileCompliance;

use App\Compliance\Application\Service\AmountFormatter;
use App\Compliance\Domain\ReadModel\ObligationProjection;
use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceStatus;

class ProfileComplianceDTOTransformer
{
    public function __construct(private readonly ComplianceBucketResolver $resolver)
    {
    }

    /**
     * @param list<ObligationProjection> $rows строки проекции ОДНОГО профиля
     */
    public function fromRows(array $rows, string $profileId, \DateTimeImmutable $now): ProfileComplianceDTO
    {
        $dto = new ProfileComplianceDTO();
        $dto->profileId = $profileId;

        $worst = ComplianceStatus::Green;
        foreach ($rows as $obligation) {
            $row = $this->rowDto($obligation, $now);
            $dto->obligations[] = $row;
            $worst = ComplianceStatus::worseOf($worst, ComplianceStatus::from($row->status));
        }
        $dto->worstStatus = $worst->value;

        return $dto;
    }

    private function rowDto(ObligationProjection $obligation, \DateTimeImmutable $now): ObligationRowDTO
    {
        $row = new ObligationRowDTO();
        $row->key = $obligation->key();
        $row->requirementId = $obligation->requirementId();
        $row->requirementName = $obligation->requirementName() ?? '';
        $row->label = $obligation->label();
        $row->type = $obligation->type()->value;
        // Структурная периодичность (kind+число+единица) — из cadence_struct (JSONB); нужна форме выдачи для
        // ПЕРЕСЧЁТА (клиентский предпросмотр «след. срок»), не только показа. null — переходный случай (строка
        // написана до добавления колонки и ещё не переписана ни одним write-through) — отдаём пустой каданс,
        // как было до его появления.
        $cadence = $obligation->cadenceStruct();
        $row->cadenceKind = $cadence?->kind->value ?? '';
        $row->cadenceNumber = $cadence?->number;
        $row->cadenceUnit = $cadence?->unit?->value;
        $row->cadenceLabel = $obligation->cadence();
        $amount = $obligation->quantity();
        $unit = $obligation->quantityUnit();
        $row->quantityLabel = null !== $amount && null !== $unit ? AmountFormatter::trimmed($amount).' '.$unit->title() : '';
        $row->quantityValue = null === $amount ? null : AmountFormatter::trimmed($amount);
        $row->quantityUnit = $unit?->value;
        $row->lastFulfilledAt = $obligation->lastFulfilledAt()?->format('Y-m-d');
        $row->nextDueAt = $obligation->nextDueAt()?->format('Y-m-d');
        $row->active = $obligation->isActive();
        $row->origin = $obligation->origin();
        // Один вызов резолвера — канонический 4-бакет (тот же, что у дашборда), трёхцветный статус выводим из
        // него же {@see ComplianceStatus::fromBucket}, не считаем параллельным ладдером.
        $bucket = $this->resolver->bucketFor($obligation, $now);
        $row->status = ComplianceStatus::fromBucket($bucket)->value;
        $row->bucket = $bucket->value;
        $row->bucketLabel = $bucket->label($obligation->type());

        return $row;
    }
}
