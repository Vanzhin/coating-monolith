<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

/**
 * Bag-of-fields поиска отделов (typeahead/список). Расширяется полями, а не новыми методами репозитория.
 * companyId — скоуп по организации (водопад организация → отдел): заданный → только её отделы.
 */
class DepartmentsFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?string $title = null,
        public ?string $companyId = null,
    ) {
    }
}
