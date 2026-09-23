<?php

declare(strict_types=1);

namespace App\Reports\Domain\Repository;

use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Фильтр списка отчётов. ownerIds проставляет Application (не-админу — принудительно его id,
 * админу — то, что выбрано, либо пусто=все), чтобы владение не размазывалось по адаптерам.
 * customer/contractor/project — id-фасеты (OR внутри, AND между собой). search ищет по № акта и
 * названию проекта. Владелец/заказчик по факту одиночны (кап в UI), внутри — тот же StringCollection.
 */
class ReportsFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public StringCollection $ownerIds = new StringCollection(),
        public StringCollection $customerIds = new StringCollection(),
        public StringCollection $contractorIds = new StringCollection(),
        public StringCollection $projectIds = new StringCollection(),
        public ?ReportType $type = null,
        public ?ReportStatus $status = null,
        public ?string $search = null,
        public ReportsSort $sort = ReportsSort::DEFAULT,
    ) {
    }
}
