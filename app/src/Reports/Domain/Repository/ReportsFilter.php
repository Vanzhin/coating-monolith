<?php

declare(strict_types=1);

namespace App\Reports\Domain\Repository;

use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Shared\Domain\Repository\Pager;

/**
 * Фильтр списка отчётов. ownerId проставляет Application (не-админу — свой id, админу — null=все),
 * чтобы владение не размазывалось по адаптерам. search ищет по № акта и названию проекта.
 */
class ReportsFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?string $ownerId = null,
        public ?ReportStatus $status = null,
        public ?string $search = null,
    ) {
    }
}
