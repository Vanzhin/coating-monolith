<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Acts;

/** Строка списка актов выдачи: кто/какое требование/№/дата/статус + id документа для действий (карточка/скан/открыть). */
final class ComplianceActRowDTO
{
    public function __construct(
        public string $documentId,
        public string $profileId,
        public string $personFio,
        public string $positionTitle,
        public ?string $departmentTitle,
        public string $requirementId,
        public string $requirementName,
        public ?string $actNumber,
        public \DateTimeImmutable $date,
        public bool $signed,
        public int $positionsCount,
    ) {
    }
}
