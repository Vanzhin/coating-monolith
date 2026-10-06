<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Acts;

use App\Compliance\Domain\Aggregate\ProfileCompliance\DocumentStatus;
use App\Compliance\Domain\Repository\ComplianceActsSort;
use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Запрос списка актов выдачи. Входные поля фильтра (owner-скоуп/пред-сужение по ФИО/должности хендлер сведёт
 * в restrictProfileIds, затем вызовет единый репозиторный findByFilter). Порядок — поле фильтра (дефолт — дата акта).
 */
readonly class GetComplianceActsQuery extends Query
{
    public function __construct(
        public ?string $q = null,
        public StringCollection $profileIds = new StringCollection(),
        public StringCollection $positionIds = new StringCollection(),
        public ?string $requirementId = null,
        public ?DocumentStatus $status = null,
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
        public ComplianceActsSort $sort = ComplianceActsSort::DEFAULT,
        public ?Pager $pager = null,
    ) {
    }
}
