<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\DocumentStatus;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Bag-of-fields списка актов выдачи. Расширяется полями, а не новыми методами репозитория. Owner-скоуп (не-админ →
 * свой профиль) и пред-сужение по ФИО/должности применяет хендлер и кладёт результат в `restrictProfileIds`.
 * Акты списания сюда не входят — они живут внутри акта выдачи (привязаны к позиции).
 */
class ComplianceActsFilter
{
    public function __construct(
        // Точный набор профилей после owner-скоупа/пред-сужения: null — без сужения, пустой — никого.
        public ?StringCollection $restrictProfileIds = null,
        public ?string $requirementId = null,
        public ?DocumentStatus $status = null,
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
        public ComplianceActsSort $sort = ComplianceActsSort::DEFAULT,
        public ?Pager $pager = null,
    ) {
    }
}
