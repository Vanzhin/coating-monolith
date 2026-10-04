<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Фильтр списка профилей. Фасеты по должности/организации/отделу — id внутри jsonb-снимков
 * (OR внутри фасета, AND между собой, зеркалит ReportsFilter). search ищет по ФИО.
 */
class ProfilesFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public StringCollection $positionIds = new StringCollection(),
        public StringCollection $organizationIds = new StringCollection(),
        public StringCollection $departmentIds = new StringCollection(),
        public ?string $search = null,
    ) {
    }
}
