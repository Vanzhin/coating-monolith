<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\WriteOffAct;

use App\Compliance\Domain\Type\DocumentStatus;

/** Строка списка актов списания (карточка в ecard-grid) — лёгкий DTO, имена требования/организации резолвятся батчем в хендлере. */
final readonly class WriteOffActListItemDTO
{
    public function __construct(
        public string $id,
        public DocumentStatus $status,
        public ?string $actNumber,
        public ?\DateTimeImmutable $actDate,
        public string $requirementName,
        public string $organizationTitle,
        public int $lineCount,
        public int $profileCount,
    ) {
    }
}
