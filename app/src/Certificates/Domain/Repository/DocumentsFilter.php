<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Repository;

use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Shared\Domain\Repository\Pager;

class DocumentsFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?string $query = null,
        public ?Reference $reference = null,
        public ?DocumentKind $kind = null,
        public ?string $issuerId = null,
    ) {
    }
}
